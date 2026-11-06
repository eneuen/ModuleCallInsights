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

Not done yet:

- **Call indexer:** the worker doesn't index calls yet. That needs real CDR fixtures recorded on a 2026.6.34 PBX first.
- **Dashboard:** the page is a placeholder.
