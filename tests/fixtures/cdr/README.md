# CDR fixtures

Each JSON file here is one call scenario recorded from real SIP calls on MikoPBX 2026.6.34 with [CallSim](../../../tools/callsim/README.md). It holds:

- **`scenario`, `description`:** what was done.
- **`mikopbx_version`, `recorded_at`:** where and when it was recorded.
- **`setup`:** the test layout: extensions, queue, IVR, DIDs and provider.
- **`legs`:** every `cdr_general` row the call produced, unchanged.

Test layout: extensions 301-305. Queue 3100 rings 302 and 303. IVR 3200: key 1 goes to the queue, 2 to 304, 3 to voicemail, and a timeout goes to the queue. The carrier provider calls DID 5551000 (the IVR) and 5551001 (straight to 301) with caller ID 5550000100, and receives outbound calls to `555` plus 7 digits. The numbers are test values only.

Re-record on each new MikoPBX release (see the CallSim README) and compare. The core changes how some calls are written.

## Scenarios

| Fixture | Legs | What happens |
| --- | --- | --- |
| `internal_answered` | 1 | 301 calls 302, answered |
| `internal_no_answer` | 1 | 301 calls 302, unanswered |
| `internal_rejected` | 1 | 301 calls 302, 302 rejects |
| `inbound_direct_answered` | 1 | Carrier calls the direct DID, 301 answers |
| `inbound_ivr_timeout_queue_answered` | 4 | IVR times out to the queue, 303 answers |
| `inbound_ivr_key1_queue_answered` | 4 | Key 1 to the queue, 302 answers |
| `inbound_ivr_key2_no_answer` | 2 | Key 2 to 304, unanswered, caller hangs up |
| `inbound_ivr_key3_voicemail` | 2 | Key 3 to voicemail, caller leaves a message |
| `inbound_queue_abandoned` | 4 | In the queue, nobody answers, caller hangs up |
| `inbound_blind_transfer_refer` | 2 | 301 answers, transfers to 302 with SIP REFER |
| `inbound_blind_transfer_feature` | 2 | 301 answers, transfers to 302 with `**` |
| `inbound_attended_transfer` | 3 | 301 answers, consults 302 with `##`, then hands over |
| `inbound_pickup` | 2 | 301 rings, 302 picks up with `*8` |
| `inbound_missed_then_callback` | 2 calls | Missed call to 301, then 301 calls the number back |
| `outbound_answered` | 1 | 301 calls out through the carrier, answered |
| `outbound_no_answer` | 1 | 301 calls out, unanswered |

## What the recordings show

These are the facts call stitching has to handle. Several differ from what the core's code alone suggests.

**Legs and applications**
- **One call, one `linkedid`.** IVR, queue, transfer and pickup legs all share the caller's `linkedid`. A callback is a separate call with its own.
- **The IVR is a leg of its own:** `dst_chan` is `App:<ivr>` and `is_app` is `1`. It is never answered (`NOANSWER`, `billsec` 0) and has a `recordingfile` of its own. `dtmf_digits` holds the keys pressed, or `t` for each timeout (`t,t` when the menu repeats once). When the key leads to voicemail, no digits are recorded at all.
- **The queue is a leg of its own:** `dst_chan` is `Queue:<queue>` and `is_app` is `1`, always `NOANSWER`. When an agent answers, its `answer` equals its `endtime`, the moment the call left the queue. Each agent who was rung gets a leg with `from_account` set to the carrier and `to_account` set to the agent.
- **Voicemail looks answered.** Its leg has `dst_chan` `VOICEMAIL`, `dst_num` `voicemail`, `disposition` `ANSWERED` and `billsec` above 0, but an empty `is_app`. Only `dst_chan` shows it was not a person.
- **`appname`** is empty in every scenario here. It is set only for interception and originate legs, which the core doesn't copy to `cdr_general` when answered.

**Disposition**
- **`disposition` alone is not the outcome.** In an abandoned queue call the agents' legs have `dialstatus` `ANSWERED` but `disposition` `NOANSWER` and `billsec` 0. A missed call can be recorded as `NOANSWER` or `CANCEL`; both were seen for the same scenario.

**Transfers and pickup**
- **`transfer` and `a_transfer` stay 0, even for transferred calls.** Detect transfers from the legs instead.
  - Blind transfer, by REFER or `**`: a second leg with the same `src_chan` (the caller's channel), whose `from_account` and `to_account` are empty.
  - Attended transfer with `##`: a consult leg from the transferring extension (`src_num` 301) to the target, then a leg from the caller to the target whose `answer` equals its `start`.
- **A picked-up call keeps its original target.** On the leg answered by the person who picked up, `dst_num` is that person (302) but `to_account` is still the extension that was rung (301). The rung extension's own leg is `NOANSWER`.

**Direction**
- **Inbound calls** have the provider ID in `from_account` and the DID in `did`. **Outbound calls** have it in `to_account`, and `did` is empty.

## Known issue in MikoPBX 2026.6.34

Forwarding an extension to voicemail ("Voicemail" as the forwarding target) doesn't work. When the dialplan is generated, the target is filtered to digits, `*`, `#` and `+`, so `voicemail` becomes empty and the extension rings until the caller hangs up. The voicemail fixture therefore reaches voicemail from the IVR instead.
