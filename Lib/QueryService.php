<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Lib;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/**
 * Turns a dashboard question into SQL over the call index or the daily
 * totals, and the result into chart-ready series.
 *
 * Every metric, breakdown, interval and filter is a fixed SQL fragment from
 * the whitelists below; request values are only ever bound parameters, and
 * anything unknown is refused (plan: Security, SQL injection).
 *
 * Source: the call index answers everything within the CDR's retention.
 * The daily totals answer additive metrics by day, week or month, and are
 * used for long ranges (they are what survives the CDR cleanup, and the
 * benchmark measured them at under 1 ms for a year). Both give identical
 * numbers where both can answer (QueryServiceTest checks this).
 */
final class QueryService
{
    /** Ranges longer than this use the daily totals when they can answer. */
    public const int DAILY_TOTALS_AFTER_DAYS = 90;
    public const int MAX_RANGE_DAYS = 3660;
    /** Most buckets one series may have. */
    public const int MAX_BUCKETS = 2200;
    /** Longest range an hourly series may cover. */
    public const int MAX_HOURLY_DAYS = 92;
    public const int MAX_SERIES = 25;

    public const string SOURCE_INDEX = 'index';
    public const string SOURCE_DAILY = 'daily';

    private const string MISSED = "direction = 'in' AND outcome NOT IN ('answered', 'voicemail')";

    /**
     * Metric => [index SQL, daily-totals SQL or null when not additive].
     * {sl} is replaced by the service-level threshold in seconds.
     */
    private const array METRICS = [
        'calls'         => ['COUNT(*)', 'SUM(calls)'],
        'answered'      => ["SUM(outcome = 'answered')", 'SUM(answered)'],
        'missed'        => ['SUM(CASE WHEN ' . self::MISSED . ' THEN 1 ELSE 0 END)',
                            'SUM(CASE WHEN ' . self::MISSED . ' THEN calls ELSE 0 END)'],
        'voicemail'     => ["SUM(outcome = 'voicemail')", "SUM(CASE WHEN outcome = 'voicemail' THEN calls ELSE 0 END)"],
        'inbound'       => ["SUM(direction = 'in')", "SUM(CASE WHEN direction = 'in' THEN calls ELSE 0 END)"],
        'outbound'      => ["SUM(direction = 'out')", "SUM(CASE WHEN direction = 'out' THEN calls ELSE 0 END)"],
        'talk_seconds'  => ['SUM(talk_seconds)', 'SUM(talk_seconds)'],
        'avg_talk'      => ["ROUND(1.0 * SUM(talk_seconds) / NULLIF(SUM(outcome = 'answered'), 0), 1)",
                            'ROUND(1.0 * SUM(talk_seconds) / NULLIF(SUM(answered), 0), 1)'],
        // Inbound only: outbound calls have no time to answer.
        'avg_wait'      => ["ROUND(AVG(CASE WHEN direction = 'in' AND outcome = 'answered' THEN wait_seconds END), 1)",
                            "ROUND(1.0 * SUM(CASE WHEN direction = 'in' THEN wait_seconds ELSE 0 END)"
                            . " / NULLIF(SUM(CASE WHEN direction = 'in' THEN answered ELSE 0 END), 0), 1)"],
        'answer_rate'   => ["ROUND(100.0 * SUM(direction = 'in' AND outcome = 'answered') / NULLIF(SUM(direction = 'in'), 0), 1)",
                            "ROUND(100.0 * SUM(CASE WHEN direction = 'in' THEN answered ELSE 0 END)"
                            . " / NULLIF(SUM(CASE WHEN direction = 'in' THEN calls ELSE 0 END), 0), 1)"],
        'service_level' => ["ROUND(100.0 * SUM(direction = 'in' AND outcome = 'answered' AND wait_seconds < {sl})"
                            . " / NULLIF(SUM(direction = 'in'), 0), 1)", null],
        'unique_callers' => ["COUNT(DISTINCT CASE WHEN direction = 'in' THEN caller_num END)", null],
        'abandoned'      => ["SUM(outcome IN ('abandoned_queue', 'abandoned_ivr'))",
                             "SUM(CASE WHEN outcome IN ('abandoned_queue', 'abandoned_ivr') THEN calls ELSE 0 END)"],
        'busy_failed'    => ["SUM(outcome IN ('busy', 'failed'))", "SUM(CASE WHEN outcome IN ('busy', 'failed') THEN calls ELSE 0 END)"],
        // IVR funnel (index only), each stage a subset of the one before: reached the IVR,
        // got past it (to a person, queue or voicemail, by a key or the timeout), answered.
        'ivr_entered'    => ['SUM(ivr_seconds > 0 OR ivr_digits IS NOT NULL)', null],
        'ivr_routed'     => ["SUM((ivr_seconds > 0 OR ivr_digits IS NOT NULL) AND outcome <> 'abandoned_ivr')", null],
        // Pressed a key (not just timed out).
        'ivr_keyed'      => ["SUM(ivr_digits IS NOT NULL AND replace(replace(ivr_digits, 't', ''), ',', '') <> '')", null],
        'ivr_answered'   => ["SUM((ivr_seconds > 0 OR ivr_digits IS NOT NULL) AND outcome = 'answered')", null],
    ];

    /** Breakdown => [index column, daily-totals column or null]. */
    private const array BREAKDOWNS = [
        'outcome'     => ['outcome', 'outcome'],
        'direction'   => ['direction', 'direction'],
        'did'         => ["COALESCE(did, '')", 'did'],
        'provider'    => ["COALESCE(provider_id, '')", 'provider_id'],
        'queue'       => ["COALESCE(first_queue, '')", 'queue'],
        'answered_by' => ["COALESCE(answered_by, '')", 'answered_by'],
        'hour'        => ['local_hour', null],
        'weekday'     => ['weekday', null],
        // Keys pressed in the IVR ('' for calls that never reached one).
        'ivr_digits'  => ["COALESCE(ivr_digits, '')", null],
        // Time to answer in bands (answered inbound calls; others are '').
        'wait_band'   => ["CASE WHEN direction <> 'in' OR outcome <> 'answered' OR wait_seconds IS NULL THEN ''"
                          . " WHEN wait_seconds < 10 THEN '0-10' WHEN wait_seconds < 20 THEN '10-20'"
                          . " WHEN wait_seconds < 30 THEN '20-30' WHEN wait_seconds < 60 THEN '30-60'"
                          . " WHEN wait_seconds < 120 THEN '60-120' ELSE '120+' END", null],
    ];

    /** Interval => [index bucket SQL, daily-totals bucket SQL or null]. */
    private const array INTERVALS = [
        'none'  => ["'all'", "'all'"],
        'hour'  => ["local_date || ' ' || printf('%02d', local_hour)", null],
        'day'   => ['local_date', 'local_date'],
        // Monday of the week: back 6 days, then forward to the next Monday (itself if Monday).
        'week'  => ["date(local_date, '-6 days', 'weekday 1')", "date(local_date, '-6 days', 'weekday 1')"],
        'month' => ['substr(local_date, 1, 7)', 'substr(local_date, 1, 7)'],
    ];

    /** Filter => [index column, daily-totals column or null]. */
    private const array FILTERS = [
        'direction'   => ['direction', 'direction'],
        'outcome'     => ['outcome', 'outcome'],
        'did'         => ["COALESCE(did, '')", 'did'],
        'provider'    => ["COALESCE(provider_id, '')", 'provider_id'],
        'queue'       => ["COALESCE(first_queue, '')", 'queue'],
        'answered_by' => ["COALESCE(answered_by, '')", 'answered_by'],
        // For drill-down from heatmap cells and time charts.
        'hour'        => ['local_hour', null],
        'weekday'     => ['weekday', null],
        'local_date'  => ['local_date', 'local_date'],
        'ivr_digits'      => ["COALESCE(ivr_digits, '')", null],
        // The calling number (index only): an extension for outbound calls, the
        // outside number for inbound ones.
        'caller'          => ['caller_num', null],
        // Calls that rang these extensions (m_AgentCall), all or only unanswered.
        'rang'            => ['@rang', null],
        'rang_unanswered' => ['@rang_unanswered', null],
    ];

    /** Metrics that are plain counts or sums: 0 where nothing happened. */
    private const array COUNT_METRICS = ['calls', 'answered', 'missed', 'voicemail', 'inbound', 'outbound', 'talk_seconds',
        'abandoned', 'busy_failed', 'ivr_entered', 'ivr_routed', 'ivr_keyed', 'ivr_answered'];

    /** Most metrics one table() request may ask for. */
    public const int MAX_TABLE_METRICS = 12;

    /** Calls per drill-down page. */
    public const int PAGE_SIZE = 50;
    public const int MAX_PAGE = 2000;

    /** Daily totals hold only these service-level boundaries (see IndexStore::TTA_BUCKETS). */
    private const array SL_BUCKETS = [10 => ['tta_0_10'], 20 => ['tta_0_10', 'tta_10_20'],
        30 => ['tta_0_10', 'tta_10_20', 'tta_20_30'], 60 => ['tta_0_10', 'tta_10_20', 'tta_20_30', 'tta_30_60'],
        120 => ['tta_0_10', 'tta_10_20', 'tta_20_30', 'tta_30_60', 'tta_60_120']];

    public function __construct(private readonly PDO $db, private readonly int $serviceLevelSeconds = 20)
    {
        if ($serviceLevelSeconds < 1 || $serviceLevelSeconds > 3600) {
            throw new InvalidArgumentException('Service-level threshold must be 1-3600 seconds.');
        }
    }

    /**
     * Runs one query.
     *
     * @param array{metric?: string, from?: string, to?: string, interval?: string, breakdown?: string|null,
     *              filters?: array<string, list<string>>, source?: string} $q
     *
     * @return array{metric: string, source: string, interval: string, breakdown: ?string,
     *               buckets: list<string>, series: list<array{key: string, values: list<int|float|null>}>}
     */
    public function query(array $q): array
    {
        $metric = self::pick($q['metric'] ?? '', self::METRICS, 'metric');
        [$from, $to] = self::range($q['from'] ?? '', $q['to'] ?? '');
        $interval = self::pick($q['interval'] ?? 'day', self::INTERVALS, 'interval');
        $breakdown = ($q['breakdown'] ?? null) === null || $q['breakdown'] === ''
            ? null
            : self::pick($q['breakdown'], self::BREAKDOWNS, 'breakdown');
        $filters = self::filters($q['filters'] ?? []);
        if ($interval === 'hour' && self::days($from, $to) > self::MAX_HOURLY_DAYS) {
            throw new InvalidArgumentException('Hourly series cover at most ' . self::MAX_HOURLY_DAYS . ' days.');
        }

        $source = $this->chooseSource($metric, $interval, $breakdown, $filters, $from, $to, $q['source'] ?? null);
        $col = $source === self::SOURCE_INDEX ? 0 : 1;
        $table = $source === self::SOURCE_INDEX ? IndexStore::CALLS : IndexStore::DAILY;

        $bucketSql = self::INTERVALS[$interval][$col];
        $keySql = $breakdown === null ? "'total'" : self::BREAKDOWNS[$breakdown][$col];
        [$where, $bind] = self::where($from, $to, $filters, $col);

        $sql = sprintf(
            'SELECT %s AS bucket, %s AS series_key, %s AS value FROM %s WHERE %s GROUP BY bucket, series_key ORDER BY bucket',
            $bucketSql,
            $keySql,
            $this->metricSql($metric, $source),
            $table,
            implode(' AND ', $where)
        );
        $stmt = $this->db->prepare($sql);
        $stmt->execute($bind);

        return $this->shape($stmt->fetchAll(PDO::FETCH_ASSOC), $metric, $source, $interval, $breakdown, $from, $to);
    }

    /**
     * Several metrics for one breakdown, as table rows: one row per key with a
     * value per metric. Each metric is answered by query() (interval none),
     * so a table always agrees with the charts.
     *
     * @param list<string> $metrics
     * @return array{breakdown: ?string, rows: list<array<string, int|float|string|null>>}
     */
    public function table(array $q, array $metrics): array
    {
        $metrics = array_values(array_unique($metrics));
        if ($metrics === [] || count($metrics) > self::MAX_TABLE_METRICS) {
            throw new InvalidArgumentException('Ask for 1 to ' . self::MAX_TABLE_METRICS . ' metrics.');
        }
        $rows = [];
        foreach ($metrics as $metric) {
            $result = $this->query(['metric' => $metric, 'interval' => 'none'] + $q);
            foreach ($result['series'] as $series) {
                $rows[$series['key']]['key'] = $series['key'];
                $rows[$series['key']][$metric] = $series['values'][0];
            }
        }
        foreach ($rows as &$row) {
            foreach ($metrics as $metric) {
                $row[$metric] ??= in_array($metric, self::COUNT_METRICS, true) ? 0 : null;
            }
        }
        unset($row);
        ksort($rows, SORT_NATURAL);

        return ['breakdown' => $q['breakdown'] ?? null, 'rows' => array_values($rows)];
    }

    /**
     * One page of calls for a drill-down, newest first, from the call index.
     * The caller then reads those calls' legs from the CDR.
     *
     * @param array{from?: string, to?: string, filters?: array<string, list<string>>} $q
     *
     * @return array{total: int, page: int, page_size: int, calls: list<array<string, mixed>>}
     */
    public function calls(array $q, int $page = 1): array
    {
        [$from, $to] = self::range($q['from'] ?? '', $q['to'] ?? '');
        $filters = self::filters($q['filters'] ?? []);
        if ($page < 1 || $page > self::MAX_PAGE) {
            throw new InvalidArgumentException('Unknown page.');
        }
        [$where, $bind] = self::where($from, $to, $filters, 0);
        $whereSql = implode(' AND ', $where);

        $count = $this->db->prepare('SELECT COUNT(*) FROM ' . IndexStore::CALLS . " WHERE $whereSql");
        $count->execute($bind);

        $stmt = $this->db->prepare(
            'SELECT linkedid, start_time, end_time, direction, did, provider_id, first_queue, ivr_digits, answered_by,
                    outcome, ivr_seconds, wait_seconds, talk_seconds, total_seconds, leg_count, transfer_count,
                    recorded, caller_num, callee_num
             FROM ' . IndexStore::CALLS . " WHERE $whereSql ORDER BY start_time DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($bind as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':limit', self::PAGE_SIZE, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * self::PAGE_SIZE, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'total' => (int)$count->fetchColumn(),
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
            'calls' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /**
     * Per-extension figures for the Team page: calls that rang the person,
     * answered, rang without an answer, answer rate, average ring time
     * before answering, talk time, and outbound calls made.
     *
     * Filters: direction, did, queue, answered_by (the extension).
     *
     * @return list<array<string, int|float|string|null>>
     */
    public function team(array $q): array
    {
        [$from, $to] = self::range($q['from'] ?? '', $q['to'] ?? '');
        $filters = self::filters($q['filters'] ?? []);
        $columns = ['direction' => 'direction', 'did' => "COALESCE(did, '')", 'queue' => "COALESCE(first_queue, '')",
                    'answered_by' => 'extension'];
        $where = ['local_date BETWEEN :from AND :to'];
        $bind = [':from' => $from, ':to' => $to];
        foreach ($filters as $name => $values) {
            if (!isset($columns[$name])) {
                throw new InvalidArgumentException("The Team view cannot filter by $name.");
            }
            $marks = [];
            foreach ($values as $i => $value) {
                $marks[] = ":t_{$name}_$i";
                $bind[":t_{$name}_$i"] = $value;
            }
            $where[] = $columns[$name] . ' IN (' . implode(', ', $marks) . ')';
        }
        $stmt = $this->db->prepare(
            'SELECT extension, COUNT(*) AS rang, SUM(answered) AS answered, COUNT(*) - SUM(answered) AS unanswered,
                    ROUND(100.0 * SUM(answered) / COUNT(*), 1) AS answer_rate,
                    ROUND(AVG(CASE WHEN answered = 1 THEN ring_seconds END), 1) AS avg_ring,
                    SUM(talk_seconds) AS talk_seconds,
                    ROUND(1.0 * SUM(talk_seconds) / NULLIF(SUM(answered), 0), 1) AS avg_talk
             FROM ' . IndexStore::AGENTS . ' WHERE ' . implode(' AND ', $where) . ' GROUP BY extension'
        );
        $stmt->execute($bind);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[(string)$row['extension']] = $row + ['outbound' => 0, 'outbound_talk_seconds' => 0];
        }

        // Outbound calls made, unless the filters exclude outbound calls.
        $direction = $filters['direction'] ?? null;
        if (($direction === null || in_array('out', $direction, true)) && !isset($filters['queue'])) {
            $out = ["direction = 'out'", 'local_date BETWEEN :from AND :to'];
            $outBind = [':from' => $from, ':to' => $to];
            foreach (['did' => "COALESCE(did, '')", 'answered_by' => 'caller_num'] as $name => $column) {
                foreach ($filters[$name] ?? [] as $i => $value) {
                    $outBind[":o_{$name}_$i"] = $value;
                }
                if (isset($filters[$name])) {
                    $out[] = "$column IN (" . implode(', ', array_map(fn ($i) => ":o_{$name}_$i", array_keys($filters[$name]))) . ')';
                }
            }
            $stmt = $this->db->prepare('SELECT caller_num, COUNT(*), SUM(talk_seconds) FROM ' . IndexStore::CALLS
                . ' WHERE ' . implode(' AND ', $out) . ' GROUP BY caller_num');
            $stmt->execute($outBind);
            foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$extension, $count, $talk]) {
                $rows[(string)$extension] ??= ['extension' => (string)$extension, 'rang' => 0, 'answered' => 0,
                    'unanswered' => 0, 'answer_rate' => null, 'avg_ring' => null, 'talk_seconds' => 0, 'avg_talk' => null];
                $rows[(string)$extension]['outbound'] = (int)$count;
                $rows[(string)$extension]['outbound_talk_seconds'] = (int)$talk;
            }
        }
        ksort($rows, SORT_NATURAL);

        $ints = ['rang', 'answered', 'unanswered', 'talk_seconds', 'outbound', 'outbound_talk_seconds'];
        $floats = ['answer_rate', 'avg_ring', 'avg_talk'];

        return array_values(array_map(function (array $r) use ($ints, $floats): array {
            $r['extension'] = (string)$r['extension'];
            foreach ($ints as $k) {
                $r[$k] = (int)$r[$k];
            }
            foreach ($floats as $k) {
                $r[$k] = $r[$k] === null ? null : (float)$r[$k];
            }
            return $r;
        }, $rows));
    }

    /**
     * Peak number of calls in progress at once, per hour of the day, over a
     * range of up to MAX_HOURLY_DAYS days, plus the overall peak and when it
     * happened. Computed from the calls' start and end times.
     *
     * @return array{by_hour: list<int>, peak: int, peak_at: ?string}
     */
    public function concurrency(array $q): array
    {
        [$from, $to] = self::range($q['from'] ?? '', $q['to'] ?? '');
        if (self::days($from, $to) > self::MAX_HOURLY_DAYS) {
            throw new InvalidArgumentException('Concurrency covers at most ' . self::MAX_HOURLY_DAYS . ' days.');
        }
        $filters = self::filters($q['filters'] ?? []);
        [$where, $bind] = self::where($from, $to, $filters, 0);
        $stmt = $this->db->prepare('SELECT start_time, COALESCE(end_time, start_time) FROM ' . IndexStore::CALLS
            . ' WHERE ' . implode(' AND ', $where));
        $stmt->execute($bind);

        // Sweep: +1 at each start, -1 at each end; ends sort before starts at the same moment.
        $events = [];
        foreach ($stmt->fetchAll(PDO::FETCH_NUM) as [$start, $end]) {
            $events[] = [substr((string)$start, 0, 23), 1];
            $events[] = [substr((string)$end, 0, 23), -1];
        }
        usort($events, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $byHour = array_fill(0, 24, 0);
        $current = 0;
        $peak = 0;
        $peakAt = null;
        foreach ($events as [$time, $delta]) {
            $current += $delta;
            $hour = (int)substr($time, 11, 2);
            $byHour[$hour] = max($byHour[$hour], $current);
            if ($current > $peak) {
                $peak = $current;
                $peakAt = substr($time, 0, 16);
            }
        }

        return ['by_hour' => $byHour, 'peak' => $peak, 'peak_at' => $peakAt];
    }

    /** Most missed calls callers() looks at in one range. */
    public const int MAX_CALLERS_MISSED = 20000;
    public const int CALLERS_LIST_LIMIT = 200;

    /**
     * The Callers page:
     * - missed calls: inbound calls no person answered (voicemail included,
     *   since the caller still wanted someone), and how many were called
     *   back within $windowHours: an answered call with the same number
     *   afterwards, either the caller calling in again or someone calling
     *   them (outbound to that number);
     * - not_returned: numbers whose latest missed call in the range has had
     *   no answered contact since, at any time: the call-back list;
     * - top callers by inbound calls, and new vs returning callers (first
     *   call ever in the index falls inside the range or before it).
     *
     * Numbers are matched exactly, as the CDR stores them.
     *
     * @return array<string, mixed>
     */
    public function callers(array $q, int $windowHours): array
    {
        [$from, $to] = self::range($q['from'] ?? '', $q['to'] ?? '');
        if ($windowHours < 1 || $windowHours > 24 * 14) {
            throw new InvalidArgumentException('Callback window must be 1 hour to 14 days.');
        }
        $filters = self::filters($q['filters'] ?? []);
        $filters['direction'] = ['in'];
        [$where, $bind] = self::where($from, $to, $filters, 0);
        $whereSql = implode(' AND ', $where);

        $stmt = $this->db->prepare('SELECT linkedid, start_time, caller_num, outcome FROM ' . IndexStore::CALLS
            . " WHERE $whereSql AND outcome <> 'answered' AND caller_num <> '' ORDER BY start_time LIMIT " . (self::MAX_CALLERS_MISSED + 1));
        $stmt->execute($bind);
        $missed = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($missed) > self::MAX_CALLERS_MISSED) {
            throw new InvalidArgumentException('Too many missed calls in this range; choose a shorter one.');
        }

        // Answered contacts with those numbers from the start of the range on.
        $contacts = [];
        $numbers = array_values(array_unique(array_column($missed, 'caller_num')));
        foreach (array_chunk($numbers, 400) as $chunk) {
            $marks = implode(', ', array_fill(0, count($chunk), '?'));
            $c = $this->db->prepare(
                'SELECT start_time, CASE WHEN direction = \'in\' THEN caller_num ELSE callee_num END AS number FROM ' . IndexStore::CALLS
                . " WHERE outcome = 'answered' AND start_time >= ? AND ("
                . "(direction = 'in' AND caller_num IN ($marks)) OR (direction = 'out' AND callee_num IN ($marks)))"
            );
            $c->execute([$from, ...$chunk, ...$chunk]);
            foreach ($c->fetchAll(PDO::FETCH_NUM) as [$time, $number]) {
                $contacts[(string)$number][] = (string)$time;
            }
        }
        foreach ($contacts as &$times) {
            sort($times);
        }
        unset($times);

        $window = $windowHours * 3600;
        $calledBack = 0;
        $delays = [];
        $lastMissed = [];
        foreach ($missed as $m) {
            $number = (string)$m['caller_num'];
            $next = null;
            foreach ($contacts[$number] ?? [] as $time) {
                if ($time > $m['start_time']) {
                    $next = $time;
                    break;
                }
            }
            if ($next !== null) {
                $delay = self::seconds($m['start_time'], $next);
                if ($delay <= $window) {
                    $calledBack++;
                    $delays[] = $delay;
                }
            }
            $entry = $lastMissed[$number] ?? ['number' => $number, 'missed' => 0];
            $entry['missed']++;
            $entry['last_missed_at'] = (string)$m['start_time'];
            $entry['last_outcome'] = (string)$m['outcome'];
            $entry['returned'] = $next !== null;
            $lastMissed[$number] = $entry;
        }
        $notReturned = array_values(array_filter($lastMissed, fn (array $e): bool => !$e['returned']));
        usort($notReturned, fn (array $a, array $b): int => strcmp($b['last_missed_at'], $a['last_missed_at']));
        $notReturned = array_map(function (array $e): array {
            unset($e['returned']);
            return $e;
        }, array_slice($notReturned, 0, self::CALLERS_LIST_LIMIT));
        sort($delays);

        // Top callers and new vs returning.
        $top = $this->db->prepare(
            "SELECT caller_num AS number, COUNT(*) AS calls, SUM(outcome = 'answered') AS answered,
                    SUM(outcome <> 'answered') AS missed, MAX(start_time) AS last_call
             FROM " . IndexStore::CALLS . " WHERE $whereSql AND caller_num <> '' GROUP BY caller_num ORDER BY calls DESC, number LIMIT 20"
        );
        $top->execute($bind);
        $mix = $this->db->prepare(
            'SELECT SUM(first_ever >= :from) AS new_callers, SUM(first_ever < :from) AS returning_callers FROM (
                SELECT c.caller_num, (SELECT MIN(local_date) FROM ' . IndexStore::CALLS . " x
                    WHERE x.caller_num = c.caller_num AND x.direction = 'in') AS first_ever
                FROM " . IndexStore::CALLS . " c WHERE $whereSql AND c.caller_num <> '' GROUP BY c.caller_num)"
        );
        $mix->execute($bind);
        [$new, $returning] = $mix->fetch(PDO::FETCH_NUM);

        return [
            'window_hours' => $windowHours,
            'missed_calls' => count($missed),
            'called_back' => $calledBack,
            'callback_rate' => $missed === [] ? null : round(100 * $calledBack / count($missed), 1),
            'avg_callback_seconds' => $delays === [] ? null : (int)round(array_sum($delays) / count($delays)),
            'median_callback_seconds' => $delays === [] ? null : (int)$delays[intdiv(count($delays), 2)],
            'not_returned' => $notReturned,
            'top_callers' => array_map(fn (array $r): array => [
                'number' => (string)$r['number'], 'calls' => (int)$r['calls'], 'answered' => (int)$r['answered'],
                'missed' => (int)$r['missed'], 'last_call' => (string)$r['last_call'],
            ], $top->fetchAll(PDO::FETCH_ASSOC)),
            'new_callers' => (int)$new,
            'returning_callers' => (int)$returning,
        ];
    }

    private static function seconds(string $from, string $to): int
    {
        $parse = fn (string $t): float => (float)(DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', substr($t, 0, 23))
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($t, 0, 19)))->format('U.v');

        return (int)round($parse($to) - $parse($from));
    }

    /** Most nodes per call-flow stage; the rest are merged into one "other" node. */
    public const int FLOW_NODES_PER_STAGE = 12;
    public const array FLOW_STAGES = ['line', 'ivr', 'queue', 'person', 'transfer', 'outcome'];

    /**
     * Inbound call paths for a Sankey chart: line (DID) -> IVR keys -> first
     * queue -> person who answered -> transferred -> outcome. A stage a call
     * didn't pass through is skipped, so its link goes straight to the next
     * stage it reached. Every node id starts with its stage, which keeps the
     * graph acyclic. Each stage keeps its busiest FLOW_NODES_PER_STAGE nodes;
     * the rest become "<stage>:@other".
     *
     * @param array{from?: string, to?: string, filters?: array<string, list<string>>} $q
     *
     * @return array{calls: int, nodes: list<array{id: string, stage: string, key: string, value: int}>,
     *               links: list<array{source: string, target: string, value: int}>}
     */
    public function flows(array $q): array
    {
        [$from, $to] = self::range($q['from'] ?? '', $q['to'] ?? '');
        $filters = self::filters($q['filters'] ?? []);
        if (isset($filters['direction']) && $filters['direction'] !== ['in']) {
            throw new InvalidArgumentException('Call flows show inbound calls only.');
        }
        $filters['direction'] = ['in'];
        [$where, $bind] = self::where($from, $to, $filters, 0);

        $stmt = $this->db->prepare(
            "SELECT COALESCE(did, '') AS line,"
            . " CASE WHEN ivr_seconds > 0 OR ivr_digits IS NOT NULL THEN COALESCE(ivr_digits, '') END AS ivr,"
            . ' first_queue AS queue,'
            . " CASE WHEN outcome = 'answered' THEN answered_by END AS person,"
            . " CASE WHEN outcome = 'answered' AND transfer_count > 0 THEN 'transferred' END AS transfer,"
            . ' outcome, COUNT(*) AS calls'
            . ' FROM ' . IndexStore::CALLS . ' WHERE ' . implode(' AND ', $where)
            . ' GROUP BY line, ivr, queue, person, transfer, outcome'
        );
        $stmt->execute($bind);
        $paths = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Busiest nodes per stage; everything else is "other".
        $volume = [];
        foreach ($paths as $path) {
            foreach (self::FLOW_STAGES as $stage) {
                if ($path[$stage] !== null) {
                    $volume[$stage][(string)$path[$stage]] = ($volume[$stage][(string)$path[$stage]] ?? 0) + (int)$path['calls'];
                }
            }
        }
        $kept = [];
        foreach ($volume as $stage => $byKey) {
            arsort($byKey);
            $kept[$stage] = array_flip(array_map('strval', array_slice(array_keys($byKey), 0, self::FLOW_NODES_PER_STAGE)));
        }

        $nodes = [];
        $links = [];
        $total = 0;
        foreach ($paths as $path) {
            $calls = (int)$path['calls'];
            $total += $calls;
            $previous = null;
            foreach (self::FLOW_STAGES as $stage) {
                if ($path[$stage] === null) {
                    continue;
                }
                $key = isset($kept[$stage][(string)$path[$stage]]) ? (string)$path[$stage] : '@other';
                $id = "$stage:$key";
                $nodes[$id] ??= ['id' => $id, 'stage' => $stage, 'key' => $key, 'value' => 0];
                $nodes[$id]['value'] += $calls;
                if ($previous !== null) {
                    $links["$previous>$id"] ??= ['source' => $previous, 'target' => $id, 'value' => 0];
                    $links["$previous>$id"]['value'] += $calls;
                }
                $previous = $id;
            }
        }
        $order = array_flip(self::FLOW_STAGES);
        uasort($nodes, fn (array $a, array $b): int => [$order[$a['stage']], -$a['value'], $a['key']]
            <=> [$order[$b['stage']], -$b['value'], $b['key']]);

        return ['calls' => $total, 'nodes' => array_values($nodes), 'links' => array_values($links)];
    }

    /**
     * What Explore may offer: metric, breakdown, interval and filter names,
     * straight from the whitelists, so the page can never ask for anything
     * the server would refuse.
     *
     * @return array{metrics: list<array{key: string, count: bool, daily: bool}>, breakdowns: list<string>,
     *               intervals: list<string>, filters: list<string>, max_table_metrics: int}
     */
    public static function catalog(): array
    {
        $metrics = [];
        foreach (self::METRICS as $key => [, $daily]) {
            $metrics[] = ['key' => $key, 'count' => in_array($key, self::COUNT_METRICS, true),
                'daily' => $daily !== null || $key === 'service_level'];
        }

        return [
            'metrics'           => $metrics,
            'breakdowns'        => array_keys(self::BREAKDOWNS),
            'intervals'         => array_keys(self::INTERVALS),
            'filters'           => array_keys(self::FILTERS),
            'max_table_metrics' => self::MAX_TABLE_METRICS,
        ];
    }

    /** The equally long period just before [from, to], for "compare to previous period". */
    public static function previousPeriod(string $from, string $to): array
    {
        [$from, $to] = self::range($from, $to);
        $start = new DateTimeImmutable($from);
        $days = self::days($from, $to);

        return [$start->modify("-$days days")->format('Y-m-d'), $start->modify('-1 day')->format('Y-m-d')];
    }

    /** @return list<string> */
    public static function metrics(): array
    {
        return array_keys(self::METRICS);
    }

    /** @return list<string> */
    public static function breakdowns(): array
    {
        return array_keys(self::BREAKDOWNS);
    }

    private function chooseSource(string $metric, string $interval, ?string $breakdown, array $filters, string $from, string $to, ?string $forced): string
    {
        $dailyCan = $this->dailyCanAnswer($metric, $interval, $breakdown)
            && array_filter(array_keys($filters), fn (string $f): bool => self::FILTERS[$f][1] === null) === [];
        if ($forced !== null && $forced !== '') {
            $forced = self::pick($forced, [self::SOURCE_INDEX => 1, self::SOURCE_DAILY => 1], 'source');
            if ($forced === self::SOURCE_DAILY && !$dailyCan) {
                throw new InvalidArgumentException("The daily totals cannot answer this query.");
            }
            return $forced;
        }
        if (!$dailyCan) {
            return self::SOURCE_INDEX;
        }
        $days = self::days($from, $to);
        $oldestIndexed = $this->db->query('SELECT MIN(local_date) FROM ' . IndexStore::CALLS)->fetchColumn();

        return $days > self::DAILY_TOTALS_AFTER_DAYS || ($oldestIndexed !== null && $oldestIndexed !== false && $from < $oldestIndexed)
            ? self::SOURCE_DAILY
            : self::SOURCE_INDEX;
    }

    private function dailyCanAnswer(string $metric, string $interval, ?string $breakdown): bool
    {
        $metricOk = self::METRICS[$metric][1] !== null
            || ($metric === 'service_level' && isset(self::SL_BUCKETS[$this->serviceLevelSeconds]));

        return $metricOk
            && self::INTERVALS[$interval][1] !== null
            && ($breakdown === null || self::BREAKDOWNS[$breakdown][1] !== null);
    }

    private function metricSql(string $metric, string $source): string
    {
        if ($source === self::SOURCE_INDEX) {
            return str_replace('{sl}', (string)$this->serviceLevelSeconds, self::METRICS[$metric][0]);
        }
        if ($metric === 'service_level') {
            $within = implode(' + ', self::SL_BUCKETS[$this->serviceLevelSeconds]);
            return "ROUND(100.0 * SUM(CASE WHEN direction = 'in' THEN $within ELSE 0 END)"
                . " / NULLIF(SUM(CASE WHEN direction = 'in' THEN calls ELSE 0 END), 0), 1)";
        }
        return self::METRICS[$metric][1];
    }

    /**
     * @param list<array{bucket: string, series_key: string|int, value: int|float|string|null}> $rows
     */
    private function shape(array $rows, string $metric, string $source, string $interval, ?string $breakdown, string $from, string $to): array
    {
        $buckets = $this->allBuckets($interval, $from, $to);
        $values = [];
        foreach ($rows as $row) {
            if ($interval === 'hour' && !isset($buckets[$row['bucket']])) {
                $buckets[$row['bucket']] = true;
            }
            $values[(string)$row['series_key']][(string)$row['bucket']] = $row['value'] === null ? null : $row['value'] + 0;
        }
        ksort($buckets);
        if (count($values) > self::MAX_SERIES) {
            throw new InvalidArgumentException('Too many series; add a filter or choose another breakdown.');
        }
        ksort($values, SORT_NATURAL);
        $countMetric = in_array($metric, self::COUNT_METRICS, true);

        $series = [];
        foreach ($values as $key => $byBucket) {
            $series[] = [
                'key'    => (string)$key,
                // Counts are 0 where nothing happened; averages and rates are empty.
                'values' => array_map(fn (string $b) => $byBucket[$b] ?? ($countMetric ? 0 : null), array_keys($buckets)),
            ];
        }

        return [
            'metric' => $metric, 'source' => $source, 'interval' => $interval, 'breakdown' => $breakdown,
            'from' => $from, 'to' => $to, 'buckets' => array_map('strval', array_keys($buckets)), 'series' => $series,
        ];
    }

    /** @return array<string, true> Every bucket in the range, so gaps show as zero. */
    private function allBuckets(string $interval, string $from, string $to): array
    {
        $buckets = [];
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        switch ($interval) {
            case 'none':
                return ['all' => true];
            case 'hour':
                return [];          // only hours with calls; a full grid could be very long
            case 'day':
                for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
                    $buckets[$d->format('Y-m-d')] = true;
                }
                break;
            case 'week':
                for ($d = $start->modify('monday this week'); $d <= $end; $d = $d->modify('+7 days')) {
                    $buckets[$d->format('Y-m-d')] = true;
                }
                break;
            case 'month':
                for ($d = $start->modify('first day of this month'); $d <= $end; $d = $d->modify('+1 month')) {
                    $buckets[$d->format('Y-m')] = true;
                }
                break;
        }
        if (count($buckets) > self::MAX_BUCKETS) {
            throw new InvalidArgumentException('Too many points; choose a longer interval.');
        }
        return $buckets;
    }

    /**
     * @param array<string, list<string>> $filters
     * @return array{list<string>, array<string, string>}
     */
    private static function where(string $from, string $to, array $filters, int $col): array
    {
        $where = ['local_date BETWEEN :from AND :to'];
        $bind = [':from' => $from, ':to' => $to];
        foreach ($filters as $name => $values) {
            $marks = [];
            foreach ($values as $i => $value) {
                $marks[] = ":f_{$name}_$i";
                $bind[":f_{$name}_$i"] = $value;
            }
            $list = implode(', ', $marks);
            $where[] = match (self::FILTERS[$name][$col]) {
                '@rang' => 'linkedid IN (SELECT linkedid FROM ' . IndexStore::AGENTS . " WHERE extension IN ($list))",
                '@rang_unanswered' => 'linkedid IN (SELECT linkedid FROM ' . IndexStore::AGENTS
                    . " WHERE answered = 0 AND extension IN ($list))",
                default => self::FILTERS[$name][$col] . " IN ($list)",
            };
        }
        return [$where, $bind];
    }

    private static function days(string $from, string $to): int
    {
        return (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
    }

    /** @return array{string, string} */
    private static function range(string $from, string $to): array
    {
        foreach ([$from, $to] as $date) {
            // The pattern first: createFromFormat() throws (not returns false) on a NUL byte.
            $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Dates must be YYYY-MM-DD.');
            }
        }
        if ($from > $to) {
            throw new InvalidArgumentException('The start date is after the end date.');
        }
        if (self::days($from, $to) > self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException('The range is too long.');
        }
        return [$from, $to];
    }

    /**
     * @param mixed $filters
     * @return array<string, list<string>>
     */
    private static function filters(mixed $filters): array
    {
        if (!is_array($filters)) {
            throw new InvalidArgumentException('Filters must be a map of lists.');
        }
        $clean = [];
        foreach ($filters as $name => $values) {
            self::pick((string)$name, self::FILTERS, 'filter');
            $values = is_array($values) ? $values : [$values];
            if (count($values) > 50) {
                throw new InvalidArgumentException("Too many values for filter $name.");
            }
            $values = array_values(array_filter(array_map(
                fn ($v): string => is_scalar($v) ? substr((string)$v, 0, 64) : throw new InvalidArgumentException('Filter values must be text.'),
                $values
            ), fn (string $v): bool => $v !== '' || $name !== 'direction'));
            if ($values !== []) {
                $clean[(string)$name] = $values;
            }
        }
        return $clean;
    }

    /** @param array<string, mixed> $allowed */
    private static function pick(mixed $value, array $allowed, string $what): string
    {
        if (!is_string($value) || !array_key_exists($value, $allowed)) {
            throw new InvalidArgumentException("Unknown $what.");
        }
        return $value;
    }
}
