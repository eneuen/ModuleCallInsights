#!/usr/bin/env python3
"""Records real CDR fixtures from a disposable MikoPBX test instance.

It places real SIP calls through a MikoPBX running in Docker with host
networking, then saves the raw cdr_general rows each call produced as JSON
fixtures for the module's tests.

  callsim.py setup      create the test network, phones, carrier and PBX objects
  callsim.py run [...]  run all scenarios (or the named ones) and save fixtures
  callsim.py list       list the scenarios
  callsim.py teardown   remove the containers and the PBX objects it created

Only use it against a throwaway test PBX: it creates extensions, a queue, an
IVR menu, a provider and routes, all named "CallSim ...".

Environment:
  MIKOPBX_URL             web interface (default https://localhost)
  MIKOPBX_ADMIN_PASSWORD  admin password (required)
  MIKOPBX_CONTAINER       PBX container name (default mikopbx-dev)
"""
import json
import os
import secrets
import ssl
import subprocess
import sys
import time
import urllib.parse
import urllib.request
from pathlib import Path

from ctl import Phone

HERE = Path(__file__).resolve().parent
STATE_FILE = HERE / ".callsim-state.json"           # local only, never committed
FIXTURES_DIR = HERE.parent.parent / "tests" / "fixtures" / "cdr"

PBX_URL = os.environ.get("MIKOPBX_URL", "https://localhost").rstrip("/")
PBX_CONTAINER = os.environ.get("MIKOPBX_CONTAINER", "mikopbx-dev")
CDR_DB = "/storage/usbdisk1/mikopbx/astlogs/asterisk/cdr.db"

NETWORK = "callsim"
SUBNET = "172.30.99.0/24"
PBX_IP = "172.30.99.1"          # the PBX, seen from the test network
PHONES_IP = "172.30.99.10"
CARRIER_IP = "172.30.99.20"
IMAGE = "ci-callsim"

PHONES = ["301", "302", "303", "304", "305"]
CTRL_PORT = {"301": 4461, "302": 4462, "303": 4463, "304": 4464, "305": 4465, "carrier": 4470}
QUEUE = "3100"                  # members 302 and 303, ring all
IVR = "3200"                    # 1 -> queue, 2 -> 304; timeout -> queue
DID_MAIN = "5551000"            # carrier -> IVR
DID_DIRECT = "5551001"          # carrier -> 301
OUTBOUND_PREFIX = "555"         # 555 + 7 digits -> carrier
CALLER = "5550100"              # caller ID the carrier presents
NAME = "CallSim"


# --------------------------------------------------------------------------- REST

class Pbx:
    def __init__(self):
        password = os.environ.get("MIKOPBX_ADMIN_PASSWORD")
        if not password:
            sys.exit("Set MIKOPBX_ADMIN_PASSWORD to the test PBX's admin password.")
        # The test PBX uses a self-signed certificate.
        self.tls = ssl.create_default_context()
        self.tls.check_hostname = False
        self.tls.verify_mode = ssl.CERT_NONE
        res = self._request("POST", "/auth:login", {"login": "admin", "password": password}, auth=False)
        self.token = res["data"]["accessToken"]

    def _request(self, method, path, data=None, auth=True):
        url = f"{PBX_URL}/pbxcore/api/v3{path}"
        body = None
        headers = {"Accept": "application/json"}
        if data is not None:
            body = json.dumps(data).encode()
            headers["Content-Type"] = "application/json"
        if auth:
            headers["Authorization"] = f"Bearer {self.token}"
        req = urllib.request.Request(url, data=body, method=method, headers=headers)
        try:
            with urllib.request.urlopen(req, context=self.tls, timeout=60) as r:
                return json.loads(r.read() or b"{}")
        except urllib.error.HTTPError as e:
            detail = e.read().decode(errors="replace")[:500]
            raise RuntimeError(f"{method} {path}: HTTP {e.code}: {detail}") from None

    def get(self, path):
        return self._request("GET", path)

    def post(self, path, data):
        return self._request("POST", path, data)

    def delete(self, path):
        return self._request("DELETE", path)

    def items(self, resource):
        data = self.get(f"/{resource}?limit=1000")["data"]
        if isinstance(data, dict):
            data = data.get("data") or data.get("items") or []
        return data

    def default(self, resource):
        return self.get(f"/{resource}:getDefault")["data"]


def docker(*args, check=True, capture=False):
    return subprocess.run(["docker", *args], check=check, text=True,
                          capture_output=capture).stdout


# --------------------------------------------------------------------------- setup

def setup():
    pbx = Pbx()
    state = {"secrets": {n: secrets.token_urlsafe(12) for n in PHONES}}
    remove_pbx_objects(pbx)

    # Extensions 301-305, named "CallSim 301" etc.
    taken = {e["number"] for e in pbx.items("employees")} & set(PHONES)
    if taken:
        sys.exit(f"Extension(s) {', '.join(sorted(taken))} already exist and are not CallSim's.")
    for number in PHONES:
        emp = pbx.default("employees")
        emp.update({"number": number, "user_username": f"{NAME} {number}",
                    "sip_secret": state["secrets"][number], "sip_enableRecording": False})
        if number == "304":
            # No answer -> voicemail, for the voicemail scenario.
            emp.update({"fwd_ringlength": 10, "fwd_forwarding": "voicemail"})
        pbx.post("/employees", {k: v for k, v in emp.items() if not k.endswith("_represent")})

    # Carrier: a SIP provider without registration, matched by its IP.
    prov = pbx.default("sip-providers")
    prov.update({"description": f"{NAME} carrier", "registration_type": "none",
                 "host": CARRIER_IP, "port": 5060, "qualify": False,
                 # getDefault returns "UDP", but the validator accepts only lowercase.
                 "transport": "udp"})
    prov = pbx.post("/sip-providers", prov)["data"]
    state["provider_id"] = prov["id"]

    queue = pbx.default("call-queues")
    queue.update({"name": f"{NAME} queue", "extension": QUEUE, "strategy": "ringall",
                  "seconds_to_ring_each_member": 15,
                  "members": [{"extension": "302", "priority": 1}, {"extension": "303", "priority": 2}]})
    pbx.post("/call-queues", queue)

    ivr = pbx.default("ivr-menu")
    ivr.update({"name": f"{NAME} IVR", "extension": IVR, "timeout": "5", "number_of_repeat": "1",
                "timeout_extension": QUEUE,
                "actions": [{"digits": "1", "extension": QUEUE}, {"digits": "2", "extension": "304"}]})
    ivr.pop("represent", None)
    pbx.post("/ivr-menu", ivr)

    for did, target in ((DID_MAIN, IVR), (DID_DIRECT, "301")):
        route = pbx.default("incoming-routes")
        route.update({"rulename": f"{NAME} {did}", "number": did, "providerid": state["provider_id"],
                      "extension": target, "timeout": 60})
        pbx.post("/incoming-routes", route)

    out = pbx.default("outbound-routes")
    out.update({"rulename": f"{NAME} outbound", "providerid": state["provider_id"],
                "numberbeginswith": OUTBOUND_PREFIX, "restnumbers": 7, "priority": 1})
    out.pop("represent", None)
    pbx.post("/outbound-routes", out)

    STATE_FILE.write_text(json.dumps(state, indent=2))
    os.chmod(STATE_FILE, 0o600)

    # Test network, phones container and carrier container.
    docker("build", "-q", "-t", IMAGE, str(HERE), capture=True)
    if NETWORK not in docker("network", "ls", "--format", "{{.Name}}", capture=True).split():
        docker("network", "create", "--subnet", SUBNET, NETWORK, capture=True)
    for name in ("callsim-phones", "callsim-carrier"):
        docker("rm", "-f", name, check=False, capture=True)
    phone_args, ports = [], []
    for n in PHONES:
        phone_args.append(f"{n}={n}:{state['secrets'][n]}@{PBX_IP}:{CTRL_PORT[n]}")
        ports += ["-p", f"127.0.0.1:{CTRL_PORT[n]}:{CTRL_PORT[n]}"]
    docker("run", "-d", "--name", "callsim-phones", "--network", NETWORK, "--ip", PHONES_IP,
           *ports, IMAGE, *phone_args, capture=True)
    docker("run", "-d", "--name", "callsim-carrier", "--network", NETWORK, "--ip", CARRIER_IP,
           "-p", f"127.0.0.1:{CTRL_PORT['carrier']}:{CTRL_PORT['carrier']}",
           IMAGE, f"carrier={CALLER}:none@:{CTRL_PORT['carrier']}", capture=True)

    print("Waiting for the phones to register...")
    deadline = time.time() + 60
    while time.time() < deadline:
        out = docker("exec", PBX_CONTAINER, "asterisk", "-rx", "pjsip show contacts", capture=True)
        if all(f"{n}/" in out for n in PHONES):
            print("All phones registered.")
            return
        time.sleep(2)
    sys.exit("Phones did not register within 60 s; check `docker logs callsim-phones`.")


def _delete_named(pbx, resource, field):
    for item in pbx.items(resource):
        if str(item.get(field, "")).startswith(NAME):
            pbx.delete(f"/{resource}/{item['id']}")


def remove_pbx_objects(pbx):
    """Deletes everything CallSim created, users of an object before the object."""
    for resource, field in (("incoming-routes", "rulename"), ("outbound-routes", "rulename"),
                            ("ivr-menu", "name"), ("call-queues", "name")):
        _delete_named(pbx, resource, field)
    for p in pbx.items("sip-providers"):
        if str(p.get("description", "")).startswith(NAME):
            pbx.delete(f"/sip-providers/{p['id']}")
    for emp in pbx.items("employees"):
        if emp["number"] in PHONES and str(emp.get("user_username", "")).startswith(NAME):
            pbx.delete(f"/employees/{emp['id']}")


def teardown():
    for name in ("callsim-phones", "callsim-carrier"):
        docker("rm", "-f", name, check=False, capture=True)
    docker("network", "rm", NETWORK, check=False, capture=True)
    remove_pbx_objects(Pbx())
    STATE_FILE.unlink(missing_ok=True)
    print("Removed the CallSim containers, network and PBX objects.")


# --------------------------------------------------------------------------- calls

class Line:
    """One phone (or the carrier) under test, driven over baresip's ctrl_tcp."""

    def __init__(self, name):
        self.name = name
        self.ctl = Phone("127.0.0.1", CTRL_PORT[name])

    def dial(self, number):
        # Phones dial through their registrar; the carrier sends straight to the PBX.
        target = f"sip:{number}@{PBX_IP}:5060" if self.name == "carrier" else number
        self.ctl.command("dial", target)

    def ringing(self, timeout=20):
        return self.ctl.wait_event("CALL_INCOMING", timeout)

    def answer(self):
        self.ctl.command("accept")

    def established(self, timeout=20):
        return self.ctl.wait_event("CALL_ESTABLISHED", timeout)

    def hangup(self):
        self.ctl.command("hangup")

    def closed(self, timeout=60):
        return self.ctl.wait_event("CALL_CLOSED", timeout)

    def command(self, cmd, params=""):
        return self.ctl.command(cmd, params)


def pause(seconds):
    time.sleep(seconds)


# --------------------------------------------------------------------------- fixtures

def cdr_rows(since_id):
    sql = f"SELECT * FROM cdr_general WHERE id > {int(since_id)} ORDER BY id"
    out = docker("exec", PBX_CONTAINER, "sqlite3", "-json", CDR_DB, sql, capture=True)
    return json.loads(out) if out.strip() else []


def cdr_max_id():
    out = docker("exec", PBX_CONTAINER, "sqlite3", CDR_DB,
                 "SELECT COALESCE(MAX(id), 0) FROM cdr_general", capture=True)
    return int(out.strip() or 0)


def wait_for_legs(since_id, quiet_seconds=8, timeout=120):
    """Waits until new legs have arrived and no more have come for a while."""
    deadline = time.time() + timeout
    last_count, last_change = -1, time.time()
    while time.time() < deadline:
        rows = cdr_rows(since_id)
        if len(rows) != last_count:
            last_count, last_change = len(rows), time.time()
        elif rows and time.time() - last_change >= quiet_seconds:
            return rows
        time.sleep(2)
    return cdr_rows(since_id)


def pbx_version():
    out = docker("exec", PBX_CONTAINER, "cat", "/etc/version", check=False, capture=True)
    return out.strip() or "unknown"


def save_fixture(scenario, rows):
    FIXTURES_DIR.mkdir(parents=True, exist_ok=True)
    state = json.loads(STATE_FILE.read_text())
    fixture = {
        "scenario": scenario["name"],
        "description": scenario["description"],
        "mikopbx_version": pbx_version(),
        "recorded_at": time.strftime("%Y-%m-%d"),
        "setup": {
            "extensions": PHONES, "queue": QUEUE, "queue_members": ["302", "303"],
            "ivr": IVR, "ivr_actions": {"1": QUEUE, "2": "304", "timeout": QUEUE},
            "did_main": DID_MAIN, "did_direct": DID_DIRECT, "external_caller": CALLER,
            "provider_id": state["provider_id"], "voicemail_on_no_answer": "304",
        },
        "legs": rows,
    }
    path = FIXTURES_DIR / f"{scenario['name']}.json"
    path.write_text(json.dumps(fixture, indent=2, ensure_ascii=False) + "\n")
    linkedids = sorted({r["linkedid"] for r in rows})
    print(f"  saved {path.relative_to(HERE.parent.parent)}: {len(rows)} legs, {len(linkedids)} call(s)")


# --------------------------------------------------------------------------- scenarios

SCENARIOS = []


def scenario(description):
    def wrap(fn):
        SCENARIOS.append({"name": fn.__name__, "description": description, "run": fn})
        return fn
    return wrap


@scenario("Internal call 301 -> 302, answered after 2 s, 4 s talk, caller hangs up.")
def internal_answered(lines):
    a, b = lines["301"], lines["302"]
    a.dial("302"); b.ringing(); pause(2); b.answer(); a.established(); pause(4); a.hangup(); b.closed()


@scenario("Internal call 301 -> 302, rings 8 s unanswered, caller hangs up.")
def internal_no_answer(lines):
    a, b = lines["301"], lines["302"]
    a.dial("302"); b.ringing(); pause(8); a.hangup(); b.closed()


@scenario("Internal call 301 -> 302, callee rejects while ringing.")
def internal_rejected(lines):
    a, b = lines["301"], lines["302"]
    a.dial("302"); b.ringing(); pause(2); b.hangup(); a.closed()


@scenario("Inbound from the carrier to the direct DID, 301 answers after 3 s, 5 s talk.")
def inbound_direct_answered(lines):
    c, a = lines["carrier"], lines["301"]
    c.dial(DID_DIRECT); a.ringing(); pause(3); a.answer(); c.established(); pause(5); c.hangup(); a.closed()


@scenario("Inbound to the IVR, no key pressed: IVR times out to the queue, 303 answers.")
def inbound_ivr_timeout_queue_answered(lines):
    c, q1, q2 = lines["carrier"], lines["302"], lines["303"]
    c.dial(DID_MAIN); c.established(); q2.ringing(30); pause(2); q2.answer(); pause(5)
    c.hangup(); q2.closed()


@scenario("Inbound to the IVR, timeout to the queue, nobody answers; caller hangs up after 12 s in queue.")
def inbound_queue_abandoned(lines):
    c, q1 = lines["carrier"], lines["302"]
    c.dial(DID_MAIN); c.established(); q1.ringing(30); pause(12); c.hangup(); q1.closed()


@scenario("Outbound call 301 -> carrier (555 prefix), answered after 2 s, 5 s talk.")
def outbound_answered(lines):
    a, c = lines["301"], lines["carrier"]
    a.dial(OUTBOUND_PREFIX + "1234567"); c.ringing(); pause(2); c.answer(); a.established(); pause(5)
    a.hangup(); c.closed()


@scenario("Outbound call 301 -> carrier, rings 10 s unanswered, caller hangs up.")
def outbound_no_answer(lines):
    a, c = lines["301"], lines["carrier"]
    a.dial(OUTBOUND_PREFIX + "1234567"); c.ringing(); pause(10); a.hangup(); c.closed()


def run(names):
    if not STATE_FILE.exists():
        sys.exit("Run `callsim.py setup` first.")
    chosen = [s for s in SCENARIOS if not names or s["name"] in names]
    unknown = set(names) - {s["name"] for s in SCENARIOS}
    if unknown:
        sys.exit(f"Unknown scenario(s): {', '.join(sorted(unknown))}")
    for s in chosen:
        print(f"{s['name']}: {s['description']}")
        lines = {n: Line(n) for n in [*PHONES, "carrier"]}
        since = cdr_max_id()
        try:
            s["run"](lines)
        except Exception as e:  # noqa: BLE001 - report and carry on with the next scenario
            print(f"  FAILED: {e}")
            for line in lines.values():
                try:
                    line.hangup()
                except Exception:  # noqa: BLE001
                    pass
            time.sleep(5)
            continue
        rows = wait_for_legs(since)
        save_fixture(s, rows)
        time.sleep(3)


def main():
    cmd = sys.argv[1] if len(sys.argv) > 1 else "help"
    if cmd == "setup":
        setup()
    elif cmd == "run":
        run(sys.argv[2:])
    elif cmd == "list":
        for s in SCENARIOS:
            print(f"{s['name']:40} {s['description']}")
    elif cmd == "teardown":
        teardown()
    else:
        print(__doc__)


if __name__ == "__main__":
    main()
