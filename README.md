# Call Insights for MikoPBX

**Module ID:** `ModuleCallInsights`

A MikoPBX extension module that adds a call analytics dashboard to the admin cabinet. It keeps a small, rebuildable per-call index derived from the call detail records (CDR), plus long-term daily totals, and charts them with Apache ECharts.

> Status: early development (phase 1 of 5). The module installs and runs, but shows no charts yet. See [CHANGELOG.md](CHANGELOG.md).

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

## Layout

| Path | What it holds |
| --- | --- |
| `module.json` | Module manifest: ID, version, minimum MikoPBX version |
| `Lib/CallInsightsConf.php` | Hooks the core calls (registers the worker). No REST routes, by design |
| `Lib/WorkerCallIndex.php` | Background worker, started and supervised by the core |
| `Lib/AccessPolicy.php` | Who may use the module, and what a write request must carry |
| `Lib/StorageInfo.php` | Detects network filesystems, where WAL must stay off |
| `Models/` | Tables in the module's database: call index, daily totals, sync state, settings |
| `Setup/PbxExtensionSetup.php` | Installer: tables come from the models; adds the sidebar entry |
| `App/Controllers/`, `App/Views/` | Admin-cabinet page (currently a placeholder) and the access-denied response |
| `Messages/` | Interface text (English and Russian) |
| `tests/Unit/` | PHPUnit tests that run without a PBX |
| `tests/fixtures/` | Recorded CDR legs per call scenario (added in phase 1) |

## Development

Unit tests cover the pure-PHP parts (no PBX or Phalcon needed) and run with PHP 8.4 or later:

```bash
composer install
composer test
composer lint
```

Code that needs the MikoPBX core (models, worker, controller) is tested by installing the module on a MikoPBX 2026.6.34 instance, such as the official Docker image `ghcr.io/mikopbx/mikopbx:2026.6.34`. When packaging, leave out `tests/`, `vendor/`, the Composer and PHPUnit files and any `db/*.db` file, and replace `%ModuleVersion%` in `module.json` with the release version.

Keep code that needs no core classes (the call indexer, the query builder) free of core and Phalcon dependencies, so it stays unit-testable here.

## Security

See [SECURITY.md](SECURITY.md) for how to report a vulnerability.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
