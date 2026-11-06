<?php

// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Load benchmark: indexes a synthetic CDR database with no pauses while a
 * second process writes to the CDR the way the core does, then times
 * dashboard-style queries on the result.
 *
 *   php tools/benchmark/run.php --cdr=/data/cdr.db [--json=/data/result.json] [--paced]
 *
 * --paced back-fills exactly as the worker does (SyncPacing for normal speed:
 * a time budget per 60-second cycle and a pause after every batch) and so
 * reports the real time a first back-fill takes.
 *
 * Measures
 * - back-fill: wall time and legs per second, unpaced (the worker's real
 *   pace is derived from this);
 * - write wait: how long each concurrent CDR write takes while indexing
 *   runs (the core writes with busy_timeout 5000 and synchronous NORMAL);
 * - queries: p50/p95 of dashboard-style queries over 30, 90 and 360 days.
 *
 * Run it on the hardware you want numbers for, e.g. a 2-CPU container.
 */

declare(strict_types=1);

use Modules\ModuleCallInsights\Lib\CallIndexer;
use Modules\ModuleCallInsights\Lib\CallIndexSync;
use Modules\ModuleCallInsights\Lib\CdrReader;
use Modules\ModuleCallInsights\Lib\IndexStore;
use Modules\ModuleCallInsights\Lib\SyncPacing;
use Modules\ModuleCallInsights\Tests\Unit\Support\TestDatabases;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const WRITE_INTERVAL_MS = 200;

$opts = getopt('', ['cdr:', 'json::', 'writer', 'stop-file::', 'latency-file::', 'batch::', 'paced']);

if (isset($opts['writer'])) {
    writer((string)$opts['cdr'], (string)$opts['stop-file'], (string)$opts['latency-file']);
    exit(0);
}

$cdrFile = (string)($opts['cdr'] ?? '');
if (!is_file($cdrFile)) {
    fwrite(STDERR, "usage: run.php --cdr=FILE [--json=FILE] [--batch=500]\n");
    exit(2);
}
$paced = isset($opts['paced']);
$pacing = SyncPacing::for('wal', 'normal');
$batch = $paced ? $pacing->batchSize : (int)($opts['batch'] ?? CallIndexSync::DEFAULT_BATCH);
$dbs = new TestDatabases();
$moduleFile = $dbs->moduleDatabase();
$stopFile = $dbs->tempFile();
$latencyFile = $dbs->tempFile();
@unlink($stopFile);

$reader = new CdrReader($cdrFile);
$legsTotal = $reader->maxId();
$store = new IndexStore($moduleFile, useWal: true);
$sync = new CallIndexSync($reader, $store, new CallIndexer(CdrReader::providerIds($cdrFile . '.config.db')), $batch);
printf("CDR: %d legs, journal mode %s, batch %d\n", $legsTotal, $reader->journalMode(), $batch);
// Fix the starting point before the writer adds rows, so only the generated legs are back-filled.
$sync->initialise();

// Concurrent writer, in its own process like Asterisk's CDR worker.
$writer = proc_open(
    [PHP_BINARY, __FILE__, '--writer', "--cdr=$cdrFile", "--stop-file=$stopFile", "--latency-file=$latencyFile"],
    [1 => ['file', 'php://stdout', 'w'], 2 => ['file', 'php://stderr', 'w']],
    $pipes
);
usleep(500_000);

// Back-fill: unpaced, or paced like the worker.
$batchTimes = [];
$start = microtime(true);
$cycleStart = $start;
while (true) {
    if ($paced && microtime(true) - $cycleStart >= $pacing->backfillSeconds) {
        // The rest of the 60-second cycle is idle, as in the worker.
        usleep((int)max(0, (60 - (microtime(true) - $cycleStart)) * 1_000_000));
        $cycleStart = microtime(true);
    }
    $t = microtime(true);
    $read = $sync->backfillStep();
    if ($read === 0) {
        break;
    }
    $batchTimes[] = microtime(true) - $t;
    if ($paced) {
        usleep($pacing->pauseMs * 1000);
    }
    if (count($batchTimes) % 200 === 0) {
        printf("  ... %d batches, %.0f s\n", count($batchTimes), microtime(true) - $start);
    }
}
$backfillSeconds = microtime(true) - $start;

touch($stopFile);
proc_close($writer);
$latencies = array_map('floatval', file($latencyFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);

$result = [
    'cdr_legs' => $legsTotal,
    'calls_indexed' => $store->callCount(),
    'backfill' => [
        'paced' => $paced,
        'seconds' => round($backfillSeconds, 1),
        'legs_per_second' => (int)round($legsTotal / max($backfillSeconds, 0.001)),
        'batches' => count($batchTimes),
        'batch_ms_p95' => round(percentile($batchTimes, 95) * 1000, 1),
        'batch_ms_max' => round(max($batchTimes ?: [0]) * 1000, 1),
    ],
    'write_wait_ms' => [
        'writes' => count($latencies),
        'p50' => round(percentile($latencies, 50), 1),
        'p99' => round(percentile($latencies, 99), 1),
        'max' => round(max($latencies ?: [0]), 1),
    ],
    'queries_ms' => queries($moduleFile),
];

echo json_encode($result, JSON_PRETTY_PRINT), "\n";
if (isset($opts['json'])) {
    file_put_contents((string)$opts['json'], json_encode($result, JSON_PRETTY_PRINT) . "\n");
}

/**
 * Writes one leg every WRITE_INTERVAL_MS and records how long each write
 * took, until the stop file appears.
 */
function writer(string $cdrFile, string $stopFile, string $latencyFile): void
{
    $pdo = new PDO('sqlite:' . $cdrFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $stmt = $pdo->prepare(
        "INSERT INTO cdr_general (start, endtime, src_num, dst_num, linkedid, UNIQUEID, disposition, billsec, duration, work_completed)
         VALUES (?, ?, '301', '302', ?, ?, 'ANSWERED', 5, 7, '1')"
    );
    $out = fopen($latencyFile, 'w');
    for ($i = 0; !file_exists($stopFile); $i++) {
        $now = date('Y-m-d H:i:s');
        $t = microtime(true);
        $stmt->execute([$now, $now, "writer-$i", "writer-$i.0"]);
        fwrite($out, sprintf("%.3f\n", (microtime(true) - $t) * 1000));
        usleep(WRITE_INTERVAL_MS * 1000);
    }
    fclose($out);
}

/**
 * Dashboard-style queries over the index and the daily totals.
 *
 * @return array<string, array<string, array{p50: float, p95: float}>>
 */
function queries(string $moduleFile): array
{
    $pdo = new PDO('sqlite:' . $moduleFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $maxDate = (string)$pdo->query('SELECT MAX(local_date) FROM m_CallIndex')->fetchColumn();
    $sql = [
        'kpi_totals' => "SELECT COUNT(*), SUM(outcome = 'answered'), AVG(wait_seconds), SUM(talk_seconds),
                            COUNT(DISTINCT caller_num) FROM m_CallIndex WHERE local_date BETWEEN :from AND :to",
        'calls_per_day_by_outcome' => 'SELECT local_date, outcome, COUNT(*) FROM m_CallIndex
                            WHERE local_date BETWEEN :from AND :to GROUP BY local_date, outcome',
        'hour_weekday_heatmap' => "SELECT weekday, local_hour, COUNT(*) FROM m_CallIndex
                            WHERE local_date BETWEEN :from AND :to AND direction = 'in' GROUP BY weekday, local_hour",
        'team_leaderboard' => "SELECT answered_by, COUNT(*), SUM(talk_seconds), AVG(talk_seconds) FROM m_CallIndex
                            WHERE local_date BETWEEN :from AND :to AND outcome = 'answered' GROUP BY answered_by",
        'by_did' => 'SELECT did, outcome, COUNT(*) FROM m_CallIndex
                            WHERE local_date BETWEEN :from AND :to GROUP BY did, outcome',
        'top_callers' => "SELECT caller_num, COUNT(*) AS n FROM m_CallIndex
                            WHERE local_date BETWEEN :from AND :to AND direction = 'in'
                            GROUP BY caller_num ORDER BY n DESC LIMIT 20",
        'daily_totals_trend' => 'SELECT local_date, SUM(calls), SUM(answered), SUM(talk_seconds) FROM m_DailySummary
                            WHERE local_date BETWEEN :from AND :to GROUP BY local_date',
        'drilldown_page' => 'SELECT linkedid FROM m_CallIndex WHERE local_date BETWEEN :from AND :to
                            ORDER BY start_time DESC LIMIT 50',
    ];
    $results = [];
    foreach ([30, 90, 360] as $days) {
        $from = (new DateTimeImmutable($maxDate))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        foreach ($sql as $name => $query) {
            $stmt = $pdo->prepare($query);
            $times = [];
            for ($i = 0; $i < 20; $i++) {
                $t = microtime(true);
                $stmt->execute([':from' => $from, ':to' => $maxDate]);
                $stmt->fetchAll();
                $times[] = (microtime(true) - $t) * 1000;
            }
            $results["{$days}_days"][$name] = ['p50' => round(percentile($times, 50), 1), 'p95' => round(percentile($times, 95), 1)];
        }
    }

    return $results;
}

/** @param list<float> $values */
function percentile(array $values, float $p): float
{
    if ($values === []) {
        return 0.0;
    }
    sort($values);
    $index = (int)ceil($p / 100 * count($values)) - 1;

    return $values[max(0, min(count($values) - 1, $index))];
}
