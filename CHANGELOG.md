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

Not done yet:

- **Call indexer:** the worker doesn't index calls yet; it is built next, against the recorded fixtures.
- **Dashboard:** the page is a placeholder.
