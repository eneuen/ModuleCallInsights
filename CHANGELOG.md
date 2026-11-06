# Changelog

All notable changes to this module are listed here. Versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Phase 1: foundations

Added:

- **Module skeleton** for MikoPBX 2026.6.34 (`module.json`, config class, installer). It is based on the official ModuleTemplate, without the template's parts that bypass the core's security:
  - No `App/Module.php`: the template's copy replaces the dispatcher and skips `SecurityPlugin`.
  - No `moduleRestAPICallback`, which runs as root.
  - No REST routes of its own.
- **Sidebar entry** "Call Insights" in the same group as the built-in call history.
- **Database tables**, created from model annotations at install:
  - `m_CallIndex`: one row per call.
  - `m_DailySummary`: daily totals that outlive the CDR, with a unique index across all their dimensions.
  - `m_SyncState`: indexer bookkeeping.
  - `m_Settings`: service-level threshold, callback window and back-fill speed.
- **Background worker** `WorkerCallIndex`, supervised by the core and started through `WorkerBase::startWorker()`, so crash counting and restarts work. It runs a 60-second cycle and records when it last ran. It turns on WAL for the module database only when the database is on a local filesystem.
- **Admin-only access.** The controller checks the caller's role on every request, the same way the core resolves it: the Bearer token first, then the refresh-token cookie. Any other role, or no role, is sent to a 403 response, as JSON for AJAX and as a page otherwise.
- **Write-request rule** (`AccessPolicy::isAllowedWrite`), ready for the first write actions. A write must be a POST, sent as AJAX, with a Bearer token, because the core also accepts the login cookie alone.
- **Interface text** in English and Russian.
- **Unit tests** (PHPUnit 11, 20 tests) for the access rules and the network-filesystem check. They run without a PBX.

Verified on a MikoPBX 2026.6.34 container:

- **Install:** tables and indexes are created; the module enables cleanly.
- **Sidebar:** the entry shows next to the call history.
- **Worker:** it starts and switches the module database to WAL on local disk.
- **Access:** admins get the page and AJAX responses; requests without an admin role get 403.

Test data:

- **CallSim** (`tools/callsim/`): places real SIP calls through a disposable MikoPBX test instance and records the CDR rows each call produces. It uses baresip softphones for extensions 301-305 and a fake carrier, all in Docker, and sets up the PBX through its REST API.
- **16 recorded CDR fixtures** (`tests/fixtures/cdr/`) from MikoPBX 2026.6.34:
  - internal answered, unanswered and rejected calls;
  - inbound calls to a DID and through the IVR (key presses and timeout) to the queue, answered and abandoned;
  - voicemail;
  - blind transfers (REFER and `**`) and an attended transfer (`##`);
  - call pickup (`*8`);
  - a missed call followed by a callback;
  - outbound answered and unanswered.
  Their README records what the recordings show, such as transfer flags that stay 0 and a voicemail leg that looks answered.
- **Fixture check** (`CdrFixturesTest`): every fixture must have all 30 `cdr_general` columns, finished legs only, and no duplicated leg.
- **Confirmed on 2026.6.34:** `cdr_general.id` is `INTEGER PRIMARY KEY AUTOINCREMENT`, and the CDR database runs in WAL mode on local storage.

Call indexer:

- **`CallIndexer`** (`Lib/CallIndexer.php`): turns the CDR legs of one call into one index row. It is pure PHP and holds every metric definition:
  - **Direction** comes from the provider IDs, checking both accounts and channel names.
  - **Kind of leg:** IVR, queue, voicemail, out-of-hours or person, told apart by `dst_chan`.
  - **Answered** means a person on the caller's side had talk time; voicemail doesn't count.
  - **Outcome** is one of answered, voicemail, abandoned in IVR, abandoned in queue, out of hours, busy, failed or no answer.
  - **Times:** wait, talk, IVR and total time.
  - **Transfers** are counted from the people who answered the caller.
  - **Duplicates:** repeated legs (same `UNIQUEID`) are dropped, keeping the newest.
- **Tests** (`CallIndexerTest`): hand-checked expectations for all 16 fixtures (17 calls), plus edge cases: duplicates, leg order, unknown provider, busy, failed, out of hours, times without milliseconds, weekday, and invalid input.

Sync:

- **`CdrReader`:** reads `cdr_general` and the provider list over read-only connections (`OPEN_READONLY` and `query_only`), in short batches.
- **`IndexStore`:** writes the call index, daily totals and sync state to the module's own database. Daily totals are grouped by direction, DID, provider, queue, answered-by and outcome, with time-to-answer buckets and no caller numbers; frozen days are never recomputed.
- **`CallIndexSync`:** the forward sync re-indexes every call a new leg belongs to; the back-fill walks history from newest to oldest. Each batch is saved in one transaction.
- **`SyncPacing`:** batch sizes and pauses per cycle. Batches are smaller and pauses longer when the CDR has no WAL (network storage); the back-fill speed setting scales the back-fill only.
- **Worker:** runs the sync every 60 seconds, new calls first, then the back-fill.
- **Verified on a 2026.6.34 container:** the back-fill indexed all 53 recorded calls with matching daily totals, and a new live call appeared in the index about 20 seconds after it ended.

Load benchmark:

- **Tools** (`tools/benchmark/`): `generate.php` builds a synthetic year of call history from the recorded fixtures. `run.php` measures the back-fill (raw or at the worker's pace), how long concurrent CDR writes wait meanwhile, and dashboard-style query times.
- **Phase-1 gate passed** at 500 calls a day over 360 days, on 2 CPUs:
  - the first back-fill takes about 2 minutes;
  - CDR writes waited at most 69 ms;
  - every query took at most 62 ms (p95).
  A 5,000-a-day year also meets every target.
- **Back-fill pacing is now a time budget per minute** (20 s at normal speed), set from these results. Before, it was a fixed number of batches, which would have taken about 54 minutes for the same year.

Not done yet:

- **Nightly job:** retention purge, freezing daily totals and reconciling updated or deleted CDR rows.
- **Network-storage benchmark:** the same measurements with the CDR on an NFS/SMB share.
- **Dashboard:** the page is a placeholder.
