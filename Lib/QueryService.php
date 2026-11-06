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
    ];

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
        $countMetric = in_array($metric, ['calls', 'answered', 'missed', 'voicemail', 'inbound', 'outbound', 'talk_seconds'], true);

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
            foreach (array_values($values) as $i => $value) {
                $marks[] = ":f_{$name}_$i";
                $bind[":f_{$name}_$i"] = $value;
            }
            $where[] = self::FILTERS[$name][$col] . ' IN (' . implode(', ', $marks) . ')';
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
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
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
