"""Starts one baresip process per SIP account inside this container.

Each account is given on the command line as  name=user:password@registrar:ctrlport
(registrar empty for an account that does not register, i.e. the carrier).
Each baresip listens for JSON commands on its own ctrl_tcp port.
"""
import os
import subprocess
import sys
import time

TEMPLATE = """\
sip_listen\t\t0.0.0.0:{sip_port}
# ausine only produces 48 kHz stereo; baresip resamples to the 8 kHz codec.
audio_source\t\tausine,440
ausrc_srate\t\t48000
auplay_srate\t\t48000
ausrc_channels\t\t2
auplay_channels\t\t2
audio_player\t\taufile,/dev/null
audio_alert\t\taufile,/dev/null
ctrl_tcp_listen\t\t0.0.0.0:{ctrl_port}
module_path\t\t/usr/lib/baresip/modules
module\t\t\tg711.so
module\t\t\tausine.so
module\t\t\taufile.so
module_app\t\tmenu.so
module_app\t\tctrl_tcp.so
module_app\t\taccount.so
"""

procs = []
for i, spec in enumerate(sys.argv[1:]):
    name, rest = spec.split("=", 1)
    cred, rest = rest.split("@", 1)
    user, password = cred.split(":", 1)
    registrar, ctrl_port = rest.rsplit(":", 1)
    home = f"/tmp/baresip-{name}"
    os.makedirs(home, exist_ok=True)
    sip_port = 5060 + 10 * i   # baresip also binds TLS on sip_port + 1
    with open(f"{home}/config", "w") as f:
        f.write(TEMPLATE.format(sip_port=sip_port, ctrl_port=ctrl_port))
    with open(f"{home}/accounts", "w") as f:
        if registrar:
            f.write(f"<sip:{user}@{registrar}>;auth_pass={password};answermode=manual;regint=60\n")
        else:
            f.write(f"<sip:{user}@127.0.0.1:{sip_port}>;regint=0;answermode=manual\n")
    log = open(f"{home}/log", "w")
    procs.append(subprocess.Popen(["baresip", "-f", home], stdout=log, stderr=subprocess.STDOUT))
    print(f"{name}: sip port {sip_port}, ctrl port {ctrl_port}", flush=True)

while all(p.poll() is None for p in procs):
    time.sleep(1)
sys.exit(1)
