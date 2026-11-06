<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Lib;

use PDO;
use Throwable;

/**
 * The module's own database (db/module.db): the call index, the daily
 * totals and the sync state. The indexer worker is its only writer.
 *
 * Tables are created by the core's installer from Models/*.php; this class
 * only reads and writes rows. Plain PDO, so it is tested without a PBX.
 */
final class IndexStore
{
    public const string CALLS = 'm_CallIndex';
    public const string DAILY = 'm_DailySummary';
    public const string STATE = 'm_SyncState';

    /** Sync state names (rows of m_SyncState). */
    public const string FORWARD_WATERMARK = 'forward_watermark';
    public const string BACKFILL_CURSOR = 'backfill_cursor';
    public const string REBUILD_REQUESTED = 'rebuild_requested';
    public const string LAST_RUN_AT = 'last_run_at';

    /** Milliseconds a statement waits for a lock (e.g. a dashboard read). */
    private const int BUSY_TIMEOUT_MS = 5000;

    /**
     * Answered calls with a time to answer (inbound and internal; outbound calls
     * have none) are counted in these buckets: seconds, upper bound exclusive.
     */
    public const array TTA_BUCKETS = [
        'tta_0_10' => [0, 10], 'tta_10_20' => [10, 20], 'tta_20_30' => [20, 30],
        'tta_30_60' => [30, 60], 'tta_60_120' => [60, 120], 'tta_120_plus' => [120, null],
    ];

    /** CallIndex columns written by the indexer (all but id). */
    private const array CALL_COLUMNS = [
        'linkedid', 'start_time', 'end_time', 'local_date', 'local_hour', 'weekday', 'direction', 'did',
        'provider_id', 'first_queue', 'ivr_digits', 'answered_by', 'outcome', 'ivr_seconds', 'wait_seconds',
        'talk_seconds', 'total_seconds', 'leg_count', 'transfer_count', 'recorded', 'caller_num', 'callee_num',
        'max_leg_id', 'logic_version',
    ];

    private PDO $db;

    /**
     * @param bool $useWal WAL only on a WAL-safe filesystem (StorageInfo); on
     *                     NFS/SMB it can corrupt the file (plan rule 11).
     */
    public function __construct(string $moduleDatabaseFile, bool $useWal)
    {
        $this->db = new PDO('sqlite:' . $moduleDatabaseFile, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->db->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        $this->db->exec($useWal ? 'PRAGMA journal_mode = WAL' : 'PRAGMA journal_mode = DELETE');
    }

    /**
     * Runs $work in one transaction: index rows, totals and sync state of a
     * batch are saved together or not at all.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function state(string $name): ?string
    {
        $stmt = $this->db->prepare('SELECT value FROM ' . self::STATE . ' WHERE name = ?');
        $stmt->execute([$name]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string)$value;
    }

    public function setState(string $name, string $value): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::STATE . ' (name, value) VALUES (?, ?) ON CONFLICT(name) DO UPDATE SET value = excluded.value'
        );
        $stmt->execute([$name, $value]);
    }

    /**
     * Inserts or replaces call rows by linkedid.
     *
     * @param list<array<string, int|string|null>> $rows CallIndexer output.
     *
     * @return list<string> The local dates touched.
     */
    public function upsertCalls(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $cols = implode(', ', self::CALL_COLUMNS);
        $marks = implode(', ', array_fill(0, count(self::CALL_COLUMNS), '?'));
        $updates = implode(', ', array_map(
            fn (string $c): string => "$c = excluded.$c",
            array_diff(self::CALL_COLUMNS, ['linkedid'])
        ));
        $stmt = $this->db->prepare(
            'INSERT INTO ' . self::CALLS . " ($cols) VALUES ($marks) ON CONFLICT(linkedid) DO UPDATE SET $updates"
        );
        $dates = [];
        foreach ($rows as $row) {
            $stmt->execute(array_map(fn (string $c) => $row[$c], self::CALL_COLUMNS));
            $dates[(string)$row['local_date']] = true;
        }

        return array_keys($dates);
    }

    /**
     * Recomputes the daily totals of the given days from the call index.
     * A day that holds frozen rows is never touched: once the CDR has
     * started deleting it, its totals are the only record (plan rule 5).
     *
     * @param list<string> $dates 'YYYY-MM-DD'
     */
    public function rebuildDailyTotals(array $dates): void
    {
        $frozen = $this->db->prepare('SELECT 1 FROM ' . self::DAILY . ' WHERE local_date = ? AND frozen = 1 LIMIT 1');
        $delete = $this->db->prepare('DELETE FROM ' . self::DAILY . ' WHERE local_date = ?');

        $buckets = [];
        foreach (self::TTA_BUCKETS as $column => [$from, $to]) {
            $range = $to === null ? "wait_seconds >= $from" : "wait_seconds >= $from AND wait_seconds < $to";
            $buckets[$column] = "SUM(CASE WHEN outcome = 'answered' AND $range THEN 1 ELSE 0 END)";
        }
        $insert = $this->db->prepare(
            'INSERT INTO ' . self::DAILY . ' (local_date, direction, did, provider_id, queue, answered_by, outcome,
                calls, answered, talk_seconds, wait_seconds, ' . implode(', ', array_keys($buckets)) . ', frozen)
             SELECT local_date, direction, COALESCE(did, \'\'), COALESCE(provider_id, \'\'), COALESCE(first_queue, \'\'),
                COALESCE(answered_by, \'\'), outcome,
                COUNT(*),
                SUM(CASE WHEN outcome = \'answered\' THEN 1 ELSE 0 END),
                SUM(talk_seconds),
                SUM(CASE WHEN outcome = \'answered\' THEN COALESCE(wait_seconds, 0) ELSE 0 END),
                ' . implode(', ', $buckets) . ',
                0
             FROM ' . self::CALLS . '
             WHERE local_date = ?
             GROUP BY local_date, direction, COALESCE(did, \'\'), COALESCE(provider_id, \'\'), COALESCE(first_queue, \'\'),
                COALESCE(answered_by, \'\'), outcome'
        );

        foreach (array_unique($dates) as $date) {
            $frozen->execute([$date]);
            if ($frozen->fetchColumn() !== false) {
                continue;
            }
            $delete->execute([$date]);
            $insert->execute([$date]);
        }
    }

    public function callCount(): int
    {
        return (int)$this->db->query('SELECT COUNT(*) FROM ' . self::CALLS)->fetchColumn();
    }

    /** @return array<string, int|string|null>|null */
    public function call(string $linkedId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . self::CALLS . ' WHERE linkedid = ?');
        $stmt->execute([$linkedId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, int|string|null>> */
    public function dailyTotals(string $date): array
    {
        $stmt = $this->db->prepare('SELECT * FROM ' . self::DAILY . ' WHERE local_date = ? ORDER BY id');
        $stmt->execute([$date]);

        return $stmt->fetchAll();
    }
}
