<?php

// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Generates a synthetic CDR database for load testing.
 *
 * Every call is a copy of one of the recorded fixtures (tests/fixtures/cdr),
 * moved to a new time with new identifiers, so the legs have exactly the
 * shapes MikoPBX writes. The mix and daily pattern resemble a small office:
 * most calls in business hours, fewer at weekends.
 *
 *   php tools/benchmark/generate.php --calls-per-day=500 --days=360 --out=/tmp/cdr.db
 *
 * Also writes <out>.config.db with the provider list (m_Providers).
 * Deterministic for a given --seed.
 */

declare(strict_types=1);

$opts = getopt('', ['calls-per-day:', 'days:', 'out:', 'seed::', 'end-date::']);
$perDay = (int)($opts['calls-per-day'] ?? 0);
$days = (int)($opts['days'] ?? 0);
$out = (string)($opts['out'] ?? '');
if ($perDay < 1 || $days < 1 || $out === '') {
    fwrite(STDERR, "usage: generate.php --calls-per-day=N --days=D --out=FILE [--seed=1] [--end-date=YYYY-MM-DD]\n");
    exit(2);
}
mt_srand((int)($opts['seed'] ?? 1));
$endDate = new DateTimeImmutable((string)($opts['end-date'] ?? 'yesterday'));

$root = dirname(__DIR__, 2);

// Call mix: fixture scenario => relative weight.
const MIX = [
    'inbound_direct_answered' => 18, 'inbound_ivr_key1_queue_answered' => 14,
    'inbound_ivr_timeout_queue_answered' => 6, 'inbound_queue_abandoned' => 5,
    'inbound_ivr_key2_no_answer' => 3, 'inbound_ivr_key3_voicemail' => 2,
    'inbound_blind_transfer_refer' => 3, 'inbound_blind_transfer_feature' => 1,
    'inbound_attended_transfer' => 2, 'inbound_pickup' => 2, 'inbound_missed_then_callback' => 4,
    'internal_answered' => 10, 'internal_no_answer' => 3, 'internal_rejected' => 1,
    'outbound_answered' => 20, 'outbound_no_answer' => 6,
];
// Share of a day's calls per hour, office pattern.
const HOURS = [0, 0, 0, 0, 0, 0, 1, 2, 5, 9, 11, 11, 8, 9, 11, 11, 9, 6, 3, 2, 1, 1, 0, 0];
const WEEKEND_FACTOR = 0.25;
const EXTERNAL_TEMPLATE = '5550000100';

// Load templates: each fixture call becomes one template (a list of legs).
$templates = [];
$weights = [];
$providerId = null;
foreach (MIX as $scenario => $weight) {
    $fixture = json_decode((string)file_get_contents("$root/tests/fixtures/cdr/$scenario.json"), true, flags: JSON_THROW_ON_ERROR);
    $providerId ??= $fixture['setup']['provider_id'];
    $calls = [];
    foreach ($fixture['legs'] as $leg) {
        $calls[$leg['linkedid']][] = $leg;
    }
    foreach ($calls as $legs) {
        usort($legs, fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        $templates[] = $legs;
        $weights[] = $weight / count($calls);
    }
}
$totalWeight = array_sum($weights);

function pick(array $weights, float $total): int
{
    $r = mt_rand() / mt_getrandmax() * $total;
    foreach ($weights as $i => $w) {
        $r -= $w;
        if ($r <= 0) {
            return $i;
        }
    }
    return count($weights) - 1;
}

function ts(string $time): float
{
    $t = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $time)
        ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $time);
    return (float)$t->format('U.v');
}

function fmt(float $ts): string
{
    return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $ts))->format('Y-m-d H:i:s.v');
}

// Build the database: real schema, indexes added after the bulk insert.
@unlink($out);
$pdo = new PDO('sqlite:' . $out, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('PRAGMA journal_mode = OFF');
$pdo->exec('PRAGMA synchronous = OFF');
$schema = (string)file_get_contents("$root/tests/fixtures/cdr_general.sql");
[$createTable, $createIndexes] = explode(';', $schema, 2);
$pdo->exec($createTable);

$columns = array_keys($templates[0][0]);
$insert = $pdo->prepare(sprintf(
    'INSERT INTO cdr_general (%s) VALUES (%s)',
    implode(',', array_map(fn (string $c): string => "`$c`", $columns)),
    implode(',', array_fill(0, count($columns), '?'))
));

$started = microtime(true);
$id = 0;
$callNo = 0;
$legCount = 0;
$pdo->beginTransaction();
for ($d = $days - 1; $d >= 0; $d--) {
    $date = $endDate->modify("-$d days");
    $weekend = (int)$date->format('N') >= 6;
    $n = (int)round($perDay * ($weekend ? WEEKEND_FACTOR : 1.0));
    $hourTotal = array_sum(HOURS);

    // Start times for the day's calls, in order.
    $starts = [];
    for ($i = 0; $i < $n; $i++) {
        $hour = pick(HOURS, $hourTotal);
        $starts[] = (float)$date->setTime($hour, 0)->format('U') + mt_rand(0, 3599999) / 1000;
    }
    sort($starts);

    foreach ($starts as $start) {
        $callNo++;
        $template = $templates[pick($weights, $totalWeight)];
        $shift = $start - ts($template[0]['start']);
        $external = '5550' . str_pad((string)mt_rand(100000, 102999), 6, '0', STR_PAD_LEFT);
        $linkedId = "bench-$callNo";
        foreach ($template as $k => $leg) {
            $id++;
            $legCount++;
            $leg['id'] = $id;
            $leg['linkedid'] = $linkedId;
            $leg['UNIQUEID'] = "$linkedId.$k";
            foreach (['start', 'answer', 'endtime'] as $col) {
                if ((string)$leg[$col] !== '') {
                    $leg[$col] = fmt(ts($leg[$col]) + $shift);
                }
            }
            foreach (['src_num', 'dst_num'] as $col) {
                if ($leg[$col] === EXTERNAL_TEMPLATE) {
                    $leg[$col] = $external;
                }
            }
            $insert->execute(array_map(fn (string $c) => $leg[$c], $columns));
        }
        if ($callNo % 20000 === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
        }
    }
}
$pdo->commit();
$inserted = microtime(true);
$pdo->exec($createIndexes);
$pdo->exec('PRAGMA journal_mode = WAL');

$config = new PDO('sqlite:' . $out . '.config.db', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$config->exec('DROP TABLE IF EXISTS m_Providers');
$config->exec('CREATE TABLE m_Providers (id INTEGER PRIMARY KEY, uniqid VARCHAR, type VARCHAR)');
$config->prepare("INSERT INTO m_Providers (uniqid, type) VALUES (?, 'SIP')")->execute([$providerId]);

printf(
    "Generated %d calls, %d legs over %d days (%s to %s) in %.1f s (+%.1f s indexes), %.0f MB\n",
    $callNo,
    $legCount,
    $days,
    $endDate->modify('-' . ($days - 1) . ' days')->format('Y-m-d'),
    $endDate->format('Y-m-d'),
    $inserted - $started,
    microtime(true) - $inserted,
    filesize($out) / 1048576
);
