# Changelog

All notable changes to this module are listed here. Versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Phase 3: depth (in progress)

Added:

- **Nightly upkeep** in the worker: a rebuild when the index was built by older metric logic (or when requested); daily totals frozen 14 days before the core's CDR cleanup reaches them; index rows purged once the CDR no longer holds their day; and a reconcile that re-indexes any day whose calls the core changed or deleted.
- **Team tab:** rings per person (rang, answered, unanswered, average ring time), from a new per-extension table, with drill-down to the unanswered rings. Index logic version 4.
- **Peak concurrent calls** by hour of the day on the Traffic tab.
- **Lines & numbers tab:** inbound calls, answer rate, wait and service level per line (DID) and per trunk.
- **Queues & IVR tab:** per-queue answer and abandon figures, wait bands, an IVR funnel (entered, routed, answered) and the keys callers pressed.
- **Callers tab:** missed calls, the share called back within the callback window, time to call back, the "missed, not called back yet" list, top callers, and new vs returning callers.
- **Exports:** a CSV button on every table and a PNG button on every chart. Exports are built in the browser; CSV cells that a spreadsheet would read as a formula (`= + - @`, tab, CR) get a leading apostrophe.
- **Daily-totals backup (Settings tab):**
  - download all daily totals as CSV;
  - import such a file to restore days the module lost (for example after a reinstall without its data). Import only adds days not already present, never changes existing ones, and freezes what it adds. The header and every value are checked; one bad line rejects the whole file, and nothing is written. Size cap 20 MiB.
  - Import is the module's first write action. It requires an AJAX POST with a Bearer token, so the login cookie alone, as a cross-site form would send it, is refused.

Security checks, done on the 2026.6.34 test PBX:

- **Import:** a cookie-only POST, a form-style POST and a GET all answer 403; a file with the wrong header answers 400 and writes nothing; a real export re-imported after deleting one day restores exactly that day (11 rows, 60 calls, frozen) and leaves the other 45 days alone.

### Phase 2: the dashboard

Added:

- **Dashboard page** with three tabs:
  - **Overview:** eight KPI tiles (calls, answered, missed, voicemail, service level, average wait, average talk, unique callers), each with its definition as a tooltip. Calls by outcome over time.
  - **Traffic patterns:** an inbound weekday-by-hour heatmap, and calls per day (bars up to 92 days, a calendar up to a year).
  - **Team:** a sortable leaderboard of answered calls and talk time per extension.
- **Shared header:**
  - date presets, a custom range, and "compare with previous period" (a dashed line on the chart, a change chip on every KPI);
  - filters for direction, line, queue and extension;
  - the view kept in the URL so it can be bookmarked or shared;
  - a "data as of" stamp, a back-fill notice, and a Live now tile fed by the core's active calls.
- **Drill-down from every chart, tile and row** to the matching calls, 50 a page. Each call expands into its legs (IVR, queue, voicemail, people, keys pressed). A call with a recording has a play button, which fetches that one call's playback link from the core only when pressed.
- **`QueryService`:** the only place dashboard SQL is built, from whitelists, with every value bound as a parameter. It answers from the call index, or from the daily totals for ranges over 90 days; where both can answer they agree exactly (checked by 347 tests).
- **JSON endpoints** `query`, `calls`, `labels` and `status`: read-only, AJAX GET only, admins only.
- **Labels:** extension, queue and provider names are read from the PBX configuration at query time.
- **ECharts 6.1.0:** tree-shaken bundle (219 KiB gzip) with its licence and notice, built with esbuild (`npm ci && npm run build`).
- **Interface text** in English and Russian (97 strings).

Changed:

- **Deleted trunks:** calls through a deleted trunk now stay inbound or outbound. Index logic version 2.

Security checks, done on the 2026.6.34 test PBX:

- **Text only:** a caller ID name and number containing `<script>` and `<img onerror>`, and an extension name containing HTML, show as plain text in the leaderboard, drill-down, legs and filter menu. No element is injected.
- **From another machine:**
  - every JSON endpoint answers 401 when logged out, with a forged Bearer token or with a forged refresh cookie;
  - the page shows the login form;
  - an admin token works;
  - non-AJAX and POST requests are refused.

Not done yet:

- **Unanswered rings per agent** (calls that rang someone without being answered) need per-agent leg data, which the index doesn't keep yet.
- **Peak concurrent calls by hour.**
- **Automatic rebuild after a logic-version change:** comes with the Rebuild button in phase 3.

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

Checks against the PBX:

- **Agreement with the built-in call history:** answered / not answered agrees on 16 of 17 recorded calls. The exception, voicemail, is deliberate: the call history counts it as answered, the index keeps it apart.
- **Recording playback:** the core's call list returns one-hour playback and download tokens per leg. They play without login, and unknown tokens get 403. The dashboard will fetch a token only when the user presses play.
- **Without WAL** (as on NFS/SMB storage): at the worker's pace a year at 500 calls a day back-fills in 16 minutes, and writes waited at most 92 ms. The no-WAL pause is now 100 ms; with the first setting, 500 ms, it would have taken about 70 minutes.

Not done yet:

- **Nightly job:** retention purge, freezing daily totals and reconciling updated or deleted CDR rows.
- **Real network share:** the no-WAL mode is measured on local disk; a real NFS/SMB share would add network latency.
- **Dashboard:** the page is a placeholder.
