# Changelog

All notable changes to this module are listed here. Versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Phase 5: release preparation

Added:

- **Release packaging:** `build/package.sh <version>` builds the install zip and its SHA-256 checksum from committed files only, with the version filled in and development files left out. The same commit always gives the same zip.
- **One list of what doesn't ship:** `.gitattributes` marks the development files `export-ignore`, so the release zip and GitHub's "Source code" archives both leave them out. `build/package.sh` relies on that list, and refuses to build if a development file still gets through.
- **Release workflow:** pushing a version tag runs every CI check on that commit, builds the zip with `build/package.sh`, and attaches it with its checksum to a draft GitHub release. The release notes are that version's CHANGELOG section, and the workflow fails if the section is missing. Nothing is published until a maintainer publishes the draft.
- **Static analysis:** PHPStan level 5 against the MikoPBX 2026.6.34 core and Phalcon stubs (`tools/phpstan/`, `composer analyse`).
- **Banned-function check:** `composer check-banned` fails if shipped PHP uses shell or code execution, `unserialize` or `call_user_func`, or if the browser script or views use `eval`, `new Function`, HTML injection, inline scripts or inline handlers. PHP is read with the tokenizer, so text inside strings doesn't count.
- **CallSim no longer hard-codes network addresses:** Docker chooses the test network's subnet, and CallSim reads the PBX gateway and carrier address back. A `CALLSIM_PBX_SIP_HOST` override covers setups where the PBX is reachable elsewhere. The phones now start only after their extensions exist, so their first registrations can't fail and get the address banned by the PBX's fail2ban.
- **CI:** a GitHub Actions workflow runs lint, the unit tests, the banned-function check, PHPStan and `npm audit` on every push, and checks that the committed browser bundles match a fresh build.
- **README:** install and uninstall instructions, and how to run every check and build a release.

Changed (dashboard design):

- **KPI cards:** the Overview and Callers figures are cards in an even grid (four across, two on narrow screens) instead of a wrapping row. Each has a small label above a large value; the change against the previous period has an arrow and is coloured only when it is good or bad news (more missed calls, voicemail or waiting is bad), and "No change" stays grey.
- **Header:** the date range and compare on one row, the four filters on the next, and live calls with the update time as a quiet status line on the right ("Updated 15:46" when it is from today).
- **Tabs:** Settings sits apart at the right of the tab bar with a cog. When the tabs don't fit, the bar scrolls sideways with a fade on the right edge, and the selected tab is scrolled into view.
- **Section headings:** every table and chart sits under a heading row that also holds its CSV or PNG button, instead of a button on a line of its own.
- **One colour palette** for every chart: the admin cabinet's blue for volumes and its tints for funnels and heatmaps, one fixed, slightly softer colour per outcome, quiet axis labels and grid lines. The overview's stacked areas are lighter, and the heatmap scale tells quiet hours apart.
- **Tables:** one style everywhere (no header fill or column dividers, aligned digits), with an arrow on the sorted column. Outcomes show a coloured dot. Long lists (missed calls not called back, top callers) show their first 15 or 10 rows with "Show all"; CSV still exports every row.
- **Call list:** a call's legs are rows under it in the same columns, with readable results ("No answer" instead of `NOANSWER`).
- **Deleted trunks** read "Deleted trunk · 9B90C86F" in muted text instead of a bare id.
- **Chart sizes** follow their content: the line chart grows with the number of lines, bars have a maximum width, and the peak-calls, wait-band and funnel charts are shorter.
- **Empty states:** every chart and table says "No calls in this range" when it has nothing to show, and adds where the history starts when the range is earlier than that.

Release testing, with a 1.0.0-rc.1 package built by `build/package.sh`:

- **Reproducible:** building the same commit twice gave byte-identical zips (same SHA-256).
- **Fresh install** on a new, empty MikoPBX 2026.6.34 container, through the same REST calls the Modules page makes (file upload, `installFromPackage`, enable): all six tables created, the worker started within a minute, switched its database to WAL, found the empty call history and finished its first cycle without errors; the dashboard's endpoints and assets answer.
- **Upgrade** over an existing install with a month of test calls: every indexed call, the daily totals (frozen rows included), per-extension rings, a saved report and changed settings were kept; the version moved to 1.0.0-rc.1 and the dashboard and saved report work.
- **Uninstall without keeping settings:** the module folder and its database are deleted, the worker stops, and no module data remains (only the core's own empty query log and caches).
- **CI** passed on GitHub for the push that added it.

Security checklist, run on the 2026.6.34 test PBX:

- **Access:** every page and action (14 reads, 5 writes) was called with no login, a forged token, a `guests` token, a token for an unknown role and an admin token (the last three signed by the test PBX itself). From another machine, all but the admin got 401 or 403. From localhost, where the core skips its own access check, all but the admin got the module's own 403, so the module's check holds by itself.
- **Hostile caller IDs:** three real inbound calls with caller-ID names `"><img src=x onerror=alert(1)>`, `<script>alert(2)</script>` and `=HYPERLINK("http://x")`, plus an extension renamed to an `<img onerror>` payload. On every tab, in chart tooltips, the call-flow chart, the filter menus, and the call list with expanded legs, they show as plain text: no element injected, no script run. In CSV the formula name is exported as `'=HYPERLINK(...)`.
- **Fuzzing:** done in phase 4, and the one finding fixed.
- **Cross-site and cookie-only writes:** refused for all five write actions (phases 3 and 4).
- **The CDR connection** refused an `UPDATE`, a `DELETE`, a `CREATE TABLE`, and a `DELETE` after `PRAGMA query_only = 0`, on the real PBX.
- **Strict CSP:** with a report-only policy of `script-src 'self'` (no inline scripts, no `eval`), every tab, tooltip and the call list loaded with no script violation from the module. The ECharts bundle does set inline `style` attributes, which the core's policy allows; a future core policy without `style-src 'unsafe-inline'` would affect chart tooltips.
- **Tools:** PHPStan level 5 (no errors after the fixes below), the banned-function check (none), `npm audit` (no vulnerabilities), and Semgrep's `php`, `javascript`, `secrets` and `security-audit` rule sets (no findings in shipped code; one warning in the CallSim test tool, which calls the URL it is configured with by design).

Fixed:

- **Chart messages never appeared:** the ECharts bundle left out the title component, so every message drawn inside a chart, such as "Choose 92 days or fewer for the hour-by-weekday view", silently showed nothing. It is now included.
- **Explore labels:** calls that went through no queue now read "No queue" (and calls nobody answered "Not answered") instead of "—" when broken down by queue or by who answered.
- **The access-denied page's title** showed a raw translation key; it now reads "Call Insights: access denied".
- **Small issues found by PHPStan:** the controller now hands its JSON to the view with `setVar()` (the interface the core reads it through) instead of a dynamic property; two `array_filter(..., 'strlen')` calls, which pass non-strings to `strlen`, now use an explicit check; an impossible `null` return type and four no-op `array_values()` calls were removed. No change in behaviour.

### Phase 4: Explore and call flows

Added:

- **Call flows tab:** a Sankey chart of inbound call paths: the line called, the IVR keys pressed, the first queue, who answered, whether the call was transferred, and how it ended. Stages a call skipped are left out, and each stage keeps its 12 busiest entries, grouping the rest as "Other". Clicking a band or a box opens the matching calls.
- **Explore tab:** pick a metric (any of the 18 the dashboard knows), a breakdown (10), an interval (automatic, hour, day, week, month or the whole range) and a chart (line, bars, stacked bars, pie or table), over the shared date range and filters.
  - The menus come from the server's own whitelists (new `catalog` endpoint), so the page can't ask for a query the server would refuse.
  - The table view puts the breakdown in rows and time in columns, with a total for counts, and has a CSV button like every table.
  - Compare with the previous period works for line and bar charts without a breakdown.
  - Clicking a point, slice or row opens the matching calls.
  - The whole view is kept in the URL (`x_metric`, `x_breakdown`, `x_interval`, `x_chart`), so it can be bookmarked or sent to a colleague.
- **Saved reports:** an Explore view can be saved under a name and reopened from a menu by any admin. A report keeps its date preset ("last 90 days" stays relative) or its fixed dates, the compare setting and the filters.
  - Stored in the new `m_SavedReport` table; the installer adds it to an existing module database.
  - Every definition is rebuilt from checked values: whitelisted names, real dates, short plain filter values, unknown keys dropped. A row that fails the check later is skipped, not trusted. Names are 1–80 characters of plain text. At most 200 reports.
  - Saving and deleting follow the write rules (AJAX POST with a Bearer token).
- **ECharts bundle** gains the pie and Sankey charts (237 KiB gzip, from 223).

Security checks, done on the 2026.6.34 test PBX:

- **Fuzzing:** 1,716 read requests across every JSON endpoint and parameter, and 39 write requests, with hostile values (quotes, SQL, markup, NUL bytes, 5,000-character strings). After the fix below, every response was 200 or a 400 with a reason; no 500s, nothing saved.
- **Saved reports:** a report named `Outcomes <img src=x onerror=alert(1)>` shows as plain text in the menu and the selected value; no element is injected. Cookie-only POSTs to `saveReport` and `deleteReport` answer 403.
- **Call flows:** the drill-down counts match the chart (346 calls answered by one extension; 194 on one queue-to-person band).

Fixed:

- **A date containing a NUL byte** (in a dashboard request's `from` or `to`, or in an imported daily-totals file) caused a 500 error instead of a 400. Found by fuzzing the endpoints; the value was rejected either way and nothing was written.

### Phase 3: depth

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
- **Settings (Settings tab):** service level target (1–600 seconds, default 20), callback window (1–720 hours, default 24) and history indexing speed (slow, normal, fast). Values are checked on the server; stored values out of range read as the defaults. Saving uses the same write rules as import.
- **Rebuild button:** asks the worker to empty the call index and index the call history again on its next cycle. Daily totals of days the CDR no longer holds are kept. Same write rules.

Not done yet:

- **Real network share:** the no-WAL mode is still measured on local disk only.
- **Call flows and the Explore page** (saved reports): phase 4.

Security checks, done on the 2026.6.34 test PBX:

- **Import:** a cookie-only POST, a form-style POST and a GET all answer 403; a file with the wrong header answers 400 and writes nothing; a real export re-imported after deleting one day restores exactly that day (11 rows, 60 calls, frozen) and leaves the other 45 days alone.
- **Cross-site requests:** a page on another origin, opened in the same browser while an admin was logged in, sent the admin's cookies to `rebuild`, `saveSettings` and `importTotals` as form posts and as fetch() calls. All answered 403 (the browser's CORS pre-check for a token header was refused too) and nothing changed.
- **From another machine:** `settings`, `exportTotals`, `saveSettings`, `rebuild` and `importTotals` answer 401 when logged out, with a forged Bearer token or with a forged refresh cookie.
- **Settings and Rebuild:** cookie-only POSTs to both answer 403; out-of-range or unknown values answer 400 and change nothing. A rebuild was picked up by the worker within a minute; all 2,214 test calls were indexed again in one cycle and the daily totals were unchanged.

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

Left for phase 3 (since done): unanswered rings per agent, peak concurrent calls by hour, and the automatic rebuild after a logic-version change.

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
