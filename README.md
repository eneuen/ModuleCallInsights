# Call Insights for MikoPBX

**Module ID:** `ModuleCallInsights`

A MikoPBX extension module that adds a call analytics dashboard to the admin cabinet. It keeps a small, rebuildable per-call index derived from the call detail records (CDR), plus long-term daily totals, and charts them with Apache ECharts.

> Status: release candidate. All planned features are in place and the release checks have passed; see [CHANGELOG.md](CHANGELOG.md).

![The Overview tab: eight KPI cards compared with the previous period, and calls by outcome per day](docs/screenshots/overview.png)

## Features

- **Pages:** Overview, Traffic patterns, Team, Lines & numbers, Queues & IVR, Call flows (a Sankey of inbound call paths), and Callers (missed calls, callbacks and the "not called back yet" list).
- **Explore:** any metric by any breakdown, over time, as a line, bar, stacked, pie or table, saved as a named report or shared as a URL.
- **Drill-down** from any chart, tile or row to the matching calls and their legs, with recording playback.
- **Exports:** CSV of any table, PNG of any chart.
- **Settings:** service level target, callback window and history indexing speed; a Rebuild button; download and import of the daily totals as a backup.

## Screenshots

The screenshots show a test PBX with simulated calls.

<table>
  <tr>
    <td width="50%" valign="top">
      <a href="docs/screenshots/call-flows.png"><img src="docs/screenshots/call-flows.png" alt="Call Flows: a Sankey chart of inbound calls from line to IVR, queue, person and outcome"></a>
      <br><b>Call Flows.</b> The path inbound calls take, from the line called through the IVR, queue and person to how the call ended.
    </td>
    <td width="50%" valign="top">
      <a href="docs/screenshots/traffic-patterns.png"><img src="docs/screenshots/traffic-patterns.png" alt="Traffic Patterns: a weekday-by-hour heatmap, calls per day and peak calls at once"></a>
      <br><b>Traffic Patterns.</b> When calls arrive by weekday and hour, calls per day, and the most calls in progress at once.
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <a href="docs/screenshots/queues-ivr.png"><img src="docs/screenshots/queues-ivr.png" alt="Queues & IVR: queue figures, time-to-answer bands, the IVR funnel and keys pressed"></a>
      <br><b>Queues & IVR.</b> Answer and abandon figures per queue, time to answer, the IVR funnel and the keys callers pressed.
    </td>
    <td width="50%" valign="top">
      <a href="docs/screenshots/callers.png"><img src="docs/screenshots/callers.png" alt="Callers: callback KPIs and the list of missed callers not called back yet"></a>
      <br><b>Callers.</b> How many missed callers were called back, how fast, and who is still waiting for a call back.
    </td>
  </tr>
  <tr>
    <td width="50%" valign="top">
      <a href="docs/screenshots/team.png"><img src="docs/screenshots/team.png" alt="Team: rings, answers, answer rate and talk time per person"></a>
      <br><b>Team.</b> Calls that rang each person, how many they answered, ring and talk time, and their share of answered calls.
    </td>
    <td width="50%" valign="top">
      <a href="docs/screenshots/explore.png"><img src="docs/screenshots/explore.png" alt="Explore: calls by outcome per week as stacked bars, with saved reports"></a>
      <br><b>Explore.</b> Any metric by any breakdown and interval, as a chart or table, saved as a report or shared as a URL.
    </td>
  </tr>
  <tr>
    <td colspan="2" valign="top">
      <a href="docs/screenshots/call-list.png"><img src="docs/screenshots/call-list.png" alt="The call list: calls with their outcome, wait and talk time, one call expanded into its legs"></a>
      <br><b>Drill-down.</b> Clicking any chart, card or row opens the matching calls. Each call opens into its legs (IVR, queue, the people it rang), with keys pressed and a play button for recordings.
    </td>
  </tr>
</table>

## Requirements

- MikoPBX 2026.6.34 or later

## Installing

Download `ModuleCallInsights-<version>.zip` from the release, check it against its `.sha256` file, and upload it on the MikoPBX **Modules** page, then enable the module. Call Insights appears in the sidebar for admins. On first start it indexes the existing call history in the background; the dashboard says so until it has finished.

To uninstall, disable and delete the module on the same page. Unless you choose to keep its settings, its database (call index, daily totals, saved reports) is deleted with it.

## How it works

- **The CDR stays the source of truth.** A background worker reads the core's call history over a read-only connection and keeps one derived row per call in the module's own database. That index can be rebuilt at any time.
- **Daily totals** keep long-term trends after the PBX deletes old call records. They hold counts and sums only, never caller numbers.
- **Admins only.** Every request is checked for the `admins` role by the module itself, on top of the core's own checks.
- **Network storage is supported.** On NFS/SMB storage SQLite cannot use WAL, so the module detects the filesystem and keeps its reads and writes short there.

## Tested limits

Benchmarked for a small office: 500 calls a day for a year, on 2 CPUs. The first back-fill of a year's history takes about 2 minutes, the PBX's own call-history writes waited at most 69 ms meanwhile, and every dashboard query took under 0.1 s. A 5,000-a-day year still meets every target. Details and method: [tools/benchmark](tools/benchmark/README.md).

## Security

See [SECURITY.md](SECURITY.md) for how to report a vulnerability.

The checks run before a release (access by every kind of user, hostile caller IDs, fuzzing, cross-site writes, the read-only call history, a strict content security policy and static analysis) and their results are listed in [CHANGELOG.md](CHANGELOG.md).

## Development

Unit tests cover the pure-PHP parts (no PBX or Phalcon needed) and run with PHP 8.4 or later:

```bash
composer install
composer test
composer lint
composer check-banned
```

`check-banned` fails if shipped code uses shell or code execution or `unserialize`, or if the browser script or views use `eval`, HTML injection, inline scripts or inline event handlers. Caller IDs come from outside callers, so the dashboard only ever inserts them as text.

Static analysis runs PHPStan against the MikoPBX core the module targets. The core and its dependencies are large (about 240 MB), so they live in their own folder:

```bash
composer install -d tools/phpstan --ignore-platform-req='ext-*'
composer analyse
```

Code that needs the MikoPBX core (models, worker, controller) is tested by installing the module on a MikoPBX 2026.6.34 instance, such as the official Docker image `ghcr.io/mikopbx/mikopbx:2026.6.34`.

The browser assets are built from `public/assets/js/src/` and `build/echarts-entry.js`, and the built files are committed:

```bash
npm ci
npm run build
```

CI (`.github/workflows/ci.yml`) runs all of the above on every push, runs `npm audit`, and checks that the committed bundles match a fresh build.

Keep code that needs no core classes (the call indexer, the query builder) free of core and Phalcon dependencies, so it stays unit-testable here.

### Layout

| Path | What it holds |
| --- | --- |
| `module.json` | Module manifest: ID, version, minimum MikoPBX version |
| `Lib/CallInsightsConf.php` | Hooks the core calls (registers the worker). No REST routes, by design |
| `Lib/WorkerCallIndex.php` | Background worker, started and supervised by the core |
| `Lib/CallIndexer.php` | Turns one call's CDR legs into one index row; every metric is defined here |
| `Lib/CallIndexSync.php` | Forward sync and back-fill, in short batches |
| `Lib/CdrReader.php` | Read-only access to the core's call history and provider list |
| `Lib/IndexStore.php` | Writes the call index, daily totals and sync state to the module database |
| `Lib/SyncPacing.php` | How hard each worker cycle may read, for local or network storage |
| `Lib/IndexMaintenance.php` | Nightly upkeep: rebuilds, freezing totals before the CDR cleanup, purge and reconcile |
| `Lib/ModuleSettings.php` | Reads, checks and saves the settings; the Rebuild request |
| `Lib/DailyTotalsCsv.php` | Daily-totals export and the strict, add-only import |
| `Lib/SavedReports.php` | Saved Explore reports: every definition checked against the query whitelists |
| `Lib/AccessPolicy.php` | Who may use the module, and what a write request must carry |
| `Lib/StorageInfo.php` | Detects network filesystems, where WAL must stay off |
| `Lib/QueryService.php` | Builds every dashboard query from whitelists, over the call index or the daily totals; call flows; the Explore catalogue |
| `Lib/Labels.php` | Extension, queue and provider names from the PBX configuration |
| `Models/` | Tables in the module's database: call index, per-extension rings, daily totals, sync state, settings, saved reports |
| `Setup/PbxExtensionSetup.php` | Installer: tables come from the models; adds the sidebar entry |
| `App/Controllers/`, `App/Views/` | The dashboard page, its JSON endpoints and the access-denied response. Reads are AJAX GETs; writes (settings, rebuild, import) must be AJAX POSTs with a Bearer token |
| `public/assets/` | Dashboard script and styles; `js/vendor/` holds the ECharts bundle and its licence |
| `Messages/` | Interface text (English and Russian) |
| `build/`, `package.json` | Builds the browser assets with esbuild (`npm ci && npm run build`) and the release zip |
| `build/package.sh` | Builds the release zip |
| `tests/Unit/` | PHPUnit tests that run without a PBX |
| `tests/fixtures/` | CDR legs recorded from real calls, and the production `cdr_general` schema |
| `tools/callsim/` | Records new CDR fixtures from real SIP calls on a test PBX |
| `tools/benchmark/` | Synthetic-history generator and load benchmark |
| `tools/check-banned.php` | Fails the build if shipped code uses a banned function |
| `tools/phpstan/` | PHPStan setup against the MikoPBX core |
| `docs/screenshots/` | The screenshots on this page |
| `.gitattributes` | Lists the development files that are left out of every release |

### Packaging a release

What ships is everything except the development files listed as `export-ignore` in `.gitattributes`: tests, tools, build scripts, script sources, documentation images, dependency manifests and CI files. That one list applies to every archive git makes, including the release zip and GitHub's "Source code" downloads. Those downloads are for developers only, because `module.json` keeps its `%ModuleVersion%` placeholder until a release is packaged. To work on the module, clone the repository.

To release a version:

1. Rename the `## [Unreleased]` heading in `CHANGELOG.md` to the version and date, for example `## [1.0.0] - 2026-11-20`, and commit.
2. Tag that commit with the version (`1.0.0` or `v1.0.0`) and push the tag.
3. The Release workflow runs every CI check on the tagged commit, builds `ModuleCallInsights-1.0.0.zip` and its SHA-256 checksum with `build/package.sh`, and attaches them to a **draft** GitHub release, with that version's CHANGELOG section as the notes.
4. Review the draft and publish it.

To build a package locally, for testing:

```bash
build/package.sh 1.0.0-rc.1
```

This writes `dist/ModuleCallInsights-1.0.0-rc.1.zip` and its checksum, built from the committed files of `HEAD` (or a commit given as the second argument), with the version filled into `module.json`. The same commit always gives the same zip.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
