# Call Insights for MikoPBX

**Module ID:** `ModuleCallInsights`

A MikoPBX extension module that adds a call analytics dashboard to the admin cabinet. It keeps a small, rebuildable per-call index derived from the call detail records (CDR), plus long-term daily totals, and charts them with Apache ECharts.

> Status: early development (phase 2 of 5). The Overview, Traffic patterns and Team pages work, with drill-down to calls and recording playback. See [CHANGELOG.md](CHANGELOG.md).

## Planned features

- Overview, traffic patterns, team, lines & numbers, queues & IVR, call flows and callers pages
- Drill-through from any chart to the matching calls, with recording playback
- An Explore page for custom questions, with saved reports and shareable URLs
- CSV and PNG export

## Requirements

- MikoPBX 2026.6.34 or later

## How it works

- **The CDR stays the source of truth.** A background worker reads the core's call history over a read-only connection and keeps one derived row per call in the module's own database. That index can be rebuilt at any time.
- **Daily totals** keep long-term trends after the PBX deletes old call records. They hold counts and sums only, never caller numbers.
- **Admins only.** Every request is checked for the `admins` role by the module itself, on top of the core's own checks.
- **Network storage is supported.** On NFS/SMB storage SQLite cannot use WAL, so the module detects the filesystem and keeps its reads and writes short there.

## Tested limits

Benchmarked for a small office: 500 calls a day for a year, on 2 CPUs. The first back-fill of a year's history takes about 2 minutes, the PBX's own call-history writes waited at most 69 ms meanwhile, and every dashboard query took under 0.1 s. A 5,000-a-day year still meets every target. Details and method: [tools/benchmark](tools/benchmark/README.md).

## Layout

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
| `Lib/AccessPolicy.php` | Who may use the module, and what a write request must carry |
| `Lib/StorageInfo.php` | Detects network filesystems, where WAL must stay off |
| `Models/` | Tables in the module's database: call index, daily totals, sync state, settings |
| `Setup/PbxExtensionSetup.php` | Installer: tables come from the models; adds the sidebar entry |
| `App/Controllers/`, `App/Views/` | The dashboard page, its JSON endpoints (query, calls, labels, status) and the access-denied response |
| `Lib/QueryService.php` | Builds every dashboard query from whitelists, over the call index or the daily totals |
| `Lib/Labels.php` | Extension, queue and provider names from the PBX configuration |
| `public/assets/` | Dashboard script and styles; `js/vendor/` holds the ECharts bundle and its licence |
| `build/`, `package.json` | Builds the browser assets with esbuild: `npm ci && npm run build` |
| `Messages/` | Interface text (English and Russian) |
| `tests/Unit/` | PHPUnit tests that run without a PBX |
| `tests/fixtures/` | CDR legs recorded from real calls, and the production `cdr_general` schema |
| `tools/callsim/` | Records new CDR fixtures from real SIP calls on a test PBX |
| `tools/benchmark/` | Synthetic-history generator and load benchmark |

## Development

Unit tests cover the pure-PHP parts (no PBX or Phalcon needed) and run with PHP 8.4 or later:

```bash
composer install
composer test
composer lint
```

Code that needs the MikoPBX core (models, worker, controller) is tested by installing the module on a MikoPBX 2026.6.34 instance, such as the official Docker image `ghcr.io/mikopbx/mikopbx:2026.6.34`. When packaging, leave out `tests/`, `tools/`, `build/`, `vendor/`, `node_modules/`, `public/assets/js/src/`, the Composer, npm and PHPUnit files and any `db/*.db` file, and replace `%ModuleVersion%` in `module.json` with the release version.

The browser assets are built from `public/assets/js/src/` and `build/echarts-entry.js`:

```bash
npm ci
npm run build
```

Keep code that needs no core classes (the call indexer, the query builder) free of core and Phalcon dependencies, so it stays unit-testable here.

## Security

See [SECURITY.md](SECURITY.md) for how to report a vulnerability.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
