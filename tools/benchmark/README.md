# Load benchmark

Measures whether Call Insights stays fast and light on a busy small-office PBX: how long the first back-fill of a year's history takes, how long the PBX's own call-history writes wait while it runs, and how fast dashboard-style queries are. The calls are synthetic: no phone calls are placed.

## Method

1. **`generate.php`** builds a synthetic `cdr_general` database. Every call is a copy of one of the calls recorded from real SIP traffic (`tests/fixtures/cdr`), moved to a new time with new identifiers, so the rows have exactly the shapes MikoPBX writes.
   - The mix is a small office's: about 60% inbound (direct, IVR, queue, voicemail, transfers, pickups), 30% outbound and 15% internal, with about 1.7 legs per call.
   - Calls follow business hours; weekends carry a quarter of the weekday volume.
2. **`run.php`** indexes that database with the module's own sync code and measures three things.
   - **Back-fill:** the time to index everything, either with no pauses (raw speed) or with `--paced`, exactly as the worker does it.
   - **Write wait:** a second process writes one call record every 200 ms the way the core does (busy timeout 5 s, WAL). Its times show how long Asterisk would wait because of the module.
   - **Queries:** p50 and p95 of dashboard-style queries over the last 30, 90 and 360 days. They cover totals, calls per day, the hour-by-weekday heatmap, the team leaderboard, calls by DID, top callers, a drill-down page and the daily-totals trend.

```bash
php tools/benchmark/generate.php --calls-per-day=500 --days=360 --out=/data/cdr.db
php tools/benchmark/run.php --cdr=/data/cdr.db --json=/data/result.json            # raw speed
php tools/benchmark/run.php --cdr=/data/cdr.db --json=/data/result.json --paced    # worker pace
```

The results below were measured in Docker's official `php:8.4-cli` image (PHP 8.4.27, the version MikoPBX 2026.6.34 runs), limited to 2 CPUs and 2 GB of memory, with the databases on a local Linux filesystem (WAL). Run `composer install` first; the runner uses the test helpers.

## Results (November 2026, module 0.1.0-dev)

Target, from the plan: **500 calls a day over 360 days**. The 5,000-a-day run shows headroom and is not a target.

| | 500 calls/day | 5,000 calls/day |
| --- | --- | --- |
| Calls / legs in 360 days | 141,750 / 269,050 | 1,417,500 / 2,691,150 |
| CDR database size | 121 MB | 1.2 GB |
| Back-fill, raw speed | 4.8 s (56,000 legs/s) | 91 s (29,500 legs/s) |
| Back-fill at the worker's pace | **about 2 minutes** (measured) | about 20 minutes (estimated) |
| CDR write wait while back-filling (max) | 1.5 ms raw, **69 ms paced** | 38 ms raw |
| Slowest query, last 30 days (p95) | 6 ms | 55 ms |
| Slowest query, last 90 days (p95) | 13 ms | 166 ms |
| Slowest query, last 360 days (p95) | 62 ms | 753 ms |
| Daily-totals trend, last 360 days (p95) | 0.6 ms | 0.7 ms |

**Gate (phase 1):** passed at 500 calls a day.

| Requirement | Limit | Result |
| --- | --- | --- |
| First back-fill of a year | under 1 hour | about 2 minutes |
| Every query (p95) | under 1 second | at most 62 ms |
| CDR write wait caused by the module | under 1 second | at most 69 ms |

**What the numbers mean for the design**

- **Pacing:** the worker's back-fill pace (`Lib/SyncPacing.php`) was set from these results: a time budget per minute with short pauses. Writes never waited long enough to matter, even with no pauses at all.
- **Long ranges should come from the daily totals.** At 5,000 calls a day, queries over a year of individual calls approach a second, while the same trend from daily totals takes under 1 ms.

## Not yet measured

- **Network storage (NFS/SMB).** There the CDR runs without WAL, and every read briefly blocks the PBX's writes. The worker already reads less and pauses longer when it detects this, but the effect hasn't been measured yet.
- **The worker on a full MikoPBX.** These runs use the module's sync code directly, without the PBX's other workers running.
