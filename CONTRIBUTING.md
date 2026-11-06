# Contributing to Call Insights

Thanks for your interest. This guide covers how to report problems, how to set up the code, the checks a change must pass, where things live, and how a release is made.

## Reporting problems and suggesting changes

- **Bugs and ideas:** open an issue. For a bug, say which MikoPBX version you run, what you did, what you expected and what happened.
- **Security problems:** please don't open a public issue; follow [SECURITY.md](SECURITY.md).
- **Pull requests:** welcome. Keep each one to a single change, add or update tests where the code allows it, and update [CHANGELOG.md](CHANGELOG.md) under `## [Unreleased]`. CI must pass.

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

Two tools support the tests and the published limits, each with its own guide:

- [tools/callsim](tools/callsim/README.md) records new CDR fixtures from real SIP calls on a throwaway test PBX. Re-record them for each MikoPBX release the module supports.
- [tools/benchmark](tools/benchmark/README.md) generates a synthetic call history and measures indexing and query times, as reported under "Tested limits" in the README.

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
| `docs/screenshots/` | The screenshots in the README |
| `.gitattributes` | Lists the development files that are left out of every release |

## Packaging a release

This section is for maintainers.

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

By contributing, you agree that your contributions are licensed under the project's license, GPL-3.0-or-later.
