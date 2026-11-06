# CDR fixtures

Each JSON file here is one call scenario recorded from real SIP calls on MikoPBX 2026.6.34 with [CallSim](../../../tools/callsim/README.md). It holds:

- **`scenario`, `description`:** what was done.
- **`mikopbx_version`, `recorded_at`:** where and when it was recorded.
- **`setup`:** the test layout: extensions, queue, IVR, DIDs and provider.
- **`legs`:** every `cdr_general` row the call produced, unchanged.

Test layout: extensions 301-305. Queue 3100 rings 302 and 303. IVR 3200: key 1 goes to the queue, 2 to 304, 3 to voicemail, and a timeout goes to the queue. Conference room 3300 has no PIN. Calls are parked with `*2` into slots 801-820 (45 s parking time on the test PBX). The carrier provider calls DID 5551000 (the IVR), 5551001 (straight to 301) and 5551002 (the conference room) with caller ID 5550000100, and receives outbound calls to `555` plus 7 digits. The numbers are test values only.

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
| `inbound_forward_no_answer_to_extension` | 2 | 301 rings 8 s unanswered and forwards to 302, who answers |
| `inbound_forward_no_answer_to_external` | 2 | 301 forwards to its mobile, an outside number reached through the carrier |
| `inbound_attended_transfer_to_queue_answered` | 5 | 301 answers, consults the queue with `##`, 302 answers, 301 hands over |
| `inbound_attended_transfer_to_queue_cancelled` | 4 | 301 consults the queue, nobody answers, 301 cancels with `*0` |
| `inbound_attended_transfer_to_queue_transferer_leaves` | 4 | 301 consults the queue and hangs up while it rings; the caller's call ends |
| `inbound_parked_retrieved` | 3 | 301 parks the caller with `*2`, 302 picks the call up from the slot |
| `inbound_parked_timeout` | 3 | 301 parks the caller, nobody picks it up, it rings 301 again |
| `inbound_conference` | 2 | The caller dials the conference DID, 301 joins the room |

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

**Forwarding**
- **A forwarded call keeps its `linkedid`.** The forwarding extension's leg is `NOANSWER` after the ring time, then a leg from the caller to the target follows.
- **An outside number can only be a forwarding target when it is an employee's mobile number.** MikoPBX creates a dialplan entry only for those (they are `EXTERNAL` rows in `m_Extensions`, named after the employee); any other outside number goes nowhere. The forwarded leg leaves through the trunk, so its `dst_chan` names the provider, but the call stays inbound.

**Attended transfer to a queue**
- **The consultation is all from the transferring extension:** a `Queue:<queue>` leg and a leg to each agent rung, all with `src_num` 301. They are not the caller's legs, so the queue is not the call's queue.
- **Cancelling with `*0`** leaves only the consultation legs, all `NOANSWER`; the caller's leg to 301 carries all the talk time.
- **Hanging up while the queue rings ends the caller's call too** on 2026.6.34 in this layout: no agent leg is ever answered.

**Parking**
- **A parked call has a `Park:<slot>` leg**, `ANSWERED`, whose `billsec` is the time spent parked. It is on hold, not a conversation, and the slot is not a person.
- **Picking up from the slot** gives a leg from the caller to the person, whose `answer` equals its `start`.
- **When the parking time runs out**, the call rings the parker again. That ring is a leg with **no channels at all** (`src_chan` and `dst_chan` empty, `src_num` the parker), `NOANSWER`, and a `start` rounded to the second, so it sorts **before** the call's real first leg. In this recording the original answered leg to 301 is the only answered leg.

**Conference rooms**
- **A conference leg has `dst_chan` `MeetMe:<room>`**, `ANSWERED`, with `billsec` the time in the room. A participant who dials in from inside joins the caller's `linkedid` with a leg of their own.

**Direction**
- **Inbound calls** have the provider ID in `from_account` and the DID in `did`. **Outbound calls** have it in `to_account`, and `did` is empty.

## Agreement with the built-in call history

The built-in call history marks a call answered when any of its legs is `ANSWERED`. Checked live through the REST API (`GET /pbxcore/api/v3/cdr?linkedid=...`) on 2026.6.34, the call index agreed on 16 of the first 17 recorded calls; `CallIndexerTest` applies the same rule to every fixture, including the later ones.

The one difference is deliberate. The voicemail call is answered in the call history but `voicemail` in the index, because nobody spoke to the caller. The dashboard must show voicemail separately (for example "answered 120, plus 8 by voicemail"), so its totals can always be reconciled with the call history page. `CallIndexerTest::testAnsweredAgreesWithTheBuiltInCallHistory` applies the core's rule to every fixture.

## Known issue in MikoPBX 2026.6.34

Forwarding an extension to voicemail ("Voicemail" as the forwarding target) doesn't work. When the dialplan is generated, the target is filtered to digits, `*`, `#` and `+`, so `voicemail` becomes empty and the extension rings until the caller hangs up. The other system targets in the forwarding menu (End call, Play busy signal, Route to extension by DID) are stripped the same way. Reported upstream as [mikopbx/Core#1381](https://github.com/mikopbx/Core/issues/1381). The voicemail fixture therefore reaches voicemail from the IVR instead.
