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
    public const string LAST_MAINTENANCE_ON = 'last_maintenance_on';

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
        'max_leg_id', 'legs_checksum', 'logic_version',
    ];

    /**
     * Columns added after the first release, in order. Append only; never
     * change or remove an entry. migrate() adds any that a database lacks,
     * because the core's installer can leave a kept (restored) database on
     * the old schema.
     */
    private const array MIGRATIONS = [
        [self::CALLS, 'legs_checksum', 'INTEGER NOT NULL DEFAULT 0'],
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
     * Adds missing columns (MIGRATIONS) to an existing database. Safe to run
     * every time: existing columns and rows are left as they are.
     *
     * @return list<string> Columns added, as "table.column".
     */
    public function migrate(): array
    {
        $added = [];
        foreach (self::MIGRATIONS as [$table, $column, $definition]) {
            $columns = $this->db->query("SELECT name FROM pragma_table_info('$table')")->fetchAll(PDO::FETCH_COLUMN);
            if ($columns !== [] && !in_array($column, $columns, true)) {
                $this->db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                $added[] = "$table.$column";
            }
        }
        return $added;
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

    /**
     * Empties the call index and its cursors so the worker indexes the CDR
     * again from scratch. Daily totals are kept: frozen days must survive, and
     * the others are recomputed as their days are indexed again.
     */
    public function resetIndex(): void
    {
        $this->transaction(function (): void {
            $this->db->exec('DELETE FROM ' . self::CALLS);
            $this->db->exec('DELETE FROM ' . self::STATE . " WHERE name IN ('"
                . self::FORWARD_WATERMARK . "', '" . self::BACKFILL_CURSOR . "', '" . self::REBUILD_REQUESTED . "')");
        });
    }

    /** True when rows were built by older metric logic. */
    public function hasRowsOlderThan(int $logicVersion): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM ' . self::CALLS . ' WHERE logic_version < ? LIMIT 1');
        $stmt->execute([$logicVersion]);

        return $stmt->fetchColumn() !== false;
    }

    /** @return int Calls removed. */
    public function deleteCallsBefore(string $date): int
    {
        $stmt = $this->db->prepare('DELETE FROM ' . self::CALLS . ' WHERE local_date < ?');
        $stmt->execute([$date]);

        return $stmt->rowCount();
    }

    /** @return int Daily-total rows frozen. */
    public function freezeTotalsBefore(string $date): int
    {
        $stmt = $this->db->prepare('UPDATE ' . self::DAILY . ' SET frozen = 1 WHERE local_date < ? AND frozen = 0');
        $stmt->execute([$date]);

        return $stmt->rowCount();
    }

    public function isDayFrozen(string $date): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM ' . self::DAILY . ' WHERE local_date = ? AND frozen = 1 LIMIT 1');
        $stmt->execute([$date]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The same fingerprint CdrReader::dayFingerprint() reads from the CDR.
     *
     * @return array{calls: int, legs: int, max_id: int, checksum: int}
     */
    public function dayFingerprint(string $date): array
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*), COALESCE(SUM(leg_count), 0), COALESCE(MAX(max_leg_id), 0), COALESCE(SUM(legs_checksum), 0)
             FROM ' . self::CALLS . ' WHERE local_date = ?'
        );
        $stmt->execute([$date]);
        [$calls, $legs, $maxId, $checksum] = $stmt->fetch(PDO::FETCH_NUM);

        return ['calls' => (int)$calls, 'legs' => (int)$legs, 'max_id' => (int)$maxId, 'checksum' => (int)$checksum];
    }

    /**
     * Removes the day's calls that are no longer in the CDR.
     *
     * @param list<string> $keepLinkedIds
     */
    public function deleteCallsOfDayExcept(string $date, array $keepLinkedIds): int
    {
        $stmt = $this->db->prepare('SELECT linkedid FROM ' . self::CALLS . ' WHERE local_date = ?');
        $stmt->execute([$date]);
        $gone = array_diff($stmt->fetchAll(PDO::FETCH_COLUMN), $keepLinkedIds);
        $delete = $this->db->prepare('DELETE FROM ' . self::CALLS . ' WHERE linkedid = ?');
        foreach ($gone as $linkedId) {
            $delete->execute([$linkedId]);
        }

        return count($gone);
    }

    /** Oldest and newest indexed day, or nulls. */
    public function indexedRange(): array
    {
        return $this->db->query('SELECT MIN(local_date), MAX(local_date) FROM ' . self::CALLS)->fetch(PDO::FETCH_NUM);
    }

    /** The back-fill speed from m_Settings ('normal' until the admin changes it). */
    public function backfillSpeed(): string
    {
        $speed = $this->db->query('SELECT backfill_speed FROM m_Settings ORDER BY id LIMIT 1')->fetchColumn();

        return $speed === false || $speed === null ? 'normal' : (string)$speed;
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
