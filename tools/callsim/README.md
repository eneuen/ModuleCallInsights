# CallSim: record CDR fixtures from real calls

CallSim places real SIP calls through a disposable MikoPBX test instance and saves the call detail records (the raw `cdr_general` rows) each call produced. The module's tests use these recordings as fixtures, so call stitching is tested against what MikoPBX really writes rather than against guesses.

Re-record the fixtures on every MikoPBX release the module supports: the core changes how some calls are written (pickups and transfers in particular).

> Use a throwaway test PBX only. CallSim creates extensions 301-305, a queue, an IVR menu, a SIP provider and routes, all named "CallSim ...", and removes them on teardown.

## How it works

- **Phones:** a container on a private Docker network runs one [baresip](https://github.com/baresip/baresip) softphone per extension (301-305). The softphones register with the PBX and are driven over baresip's `ctrl_tcp` control port.
- **Carrier:** a second container, on its own IP address, plays the phone company. The PBX knows it as a SIP provider without registration, matched by that IP. It sends inbound calls to two DIDs and answers outbound calls.
- **Driver:** `callsim.py` creates the PBX objects through the REST API, runs each scenario step by step (dial, ring, answer, hang up), then reads the new rows from the CDR database inside the PBX container.

Test layout on the PBX:

| Object | Number | Behaviour |
| --- | --- | --- |
| Extensions | 301-305 | Plain extensions |
| Queue | 3100 | Rings 302 and 303 together |
| IVR menu | 3200 | 1 to the queue, 2 to 304, 3 to voicemail, 5 s timeout to the queue |
| DID 5551000 | from the carrier | Goes to the IVR menu |
| DID 5551001 | from the carrier | Goes straight to 301 |
| Conference room | 3300 | No PIN |
| DID 5551002 | from the carrier | Goes to the conference room |
| Outbound route | 555 + 7 digits | Goes to the carrier |

Some scenarios change settings for their own duration only: forwarding on 301 (and, for the forward to an outside number, 301's mobile number, because MikoPBX forwards only to employees' mobiles), restored when the scenario ends. Calls are parked with the PBX's default parking code, `*2`.

**Out-of-hours rules.** MikoPBX sends inbound calls to its out-of-hours handling outside working hours, for every inbound route unless a rule is restricted to some routes. So that `run` works at any time, it restricts each rule to the PBX's *other* inbound routes for the length of the run and puts the rules back afterwards. The originals are saved in the state file first, and `teardown` restores them if a run was interrupted.

## Requirements

- Docker, and a MikoPBX test container with host networking. CallSim creates a Docker network called `callsim`, lets Docker choose its addresses, and reaches the PBX at that network's gateway. If your PBX is reachable at another address from that network, set `CALLSIM_PBX_SIP_HOST` to it.
- Python 3.10 or later on the machine that runs `callsim.py` (standard library only).

## Usage

```bash
export MIKOPBX_ADMIN_PASSWORD='<test PBX admin password>'
export MIKOPBX_URL=https://localhost        # default
export MIKOPBX_CONTAINER=mikopbx-dev       # default

python3 tools/callsim/callsim.py setup      # objects, network, phones; waits for registration
python3 tools/callsim/callsim.py list       # scenarios
python3 tools/callsim/callsim.py run        # all scenarios, or name some
python3 tools/callsim/callsim.py teardown   # remove everything CallSim created
```

Fixtures are written to `tests/fixtures/cdr/<scenario>.json`. Each one holds the scenario description, the MikoPBX version, the test layout and every CDR leg the call produced.

`setup` generates new SIP passwords each time and keeps them in `.callsim-state.json` next to the script. That file is local and never committed.

## Files

| File | Purpose |
| --- | --- |
| `callsim.py` | Driver: setup, scenarios, fixture capture, teardown |
| `ctl.py` | Small client for baresip's netstring/JSON control protocol |
| `phone.py` | Container entry point: starts one baresip per account |
| `Dockerfile` | Debian image with baresip and Python |

## Notes

- baresip's built-in tone generator only produces 48 kHz stereo, so the audio settings in `phone.py` use that and baresip resamples to the 8 kHz call codec.
- Each baresip binds TLS on its SIP port + 1, so the phones' SIP ports are 10 apart.
