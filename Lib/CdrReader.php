<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Lib;

use InvalidArgumentException;
use PDO;
use Pdo\Sqlite;

/**
 * Reads the core's call history (cdr_general) and provider list over
 * read-only connections. The module never writes to either database.
 *
 * - The file is opened with SQLITE_OPEN_READONLY and PRAGMA query_only, so
 *   even a bug cannot write to it (plan: Security, damaging the CDR).
 * - Every query is short and batched. With WAL, long reads stop the core's
 *   checkpoint; on NFS/SMB (no WAL), a reader blocks the writer. No
 *   transaction is held open between calls (plan rule 10).
 *
 * Plain PDO, no core dependencies, so it is tested against a real-schema
 * SQLite file without a PBX.
 */
final class CdrReader
{
    /** Milliseconds a read waits when the core is writing. */
    private const int BUSY_TIMEOUT_MS = 2000;

    private PDO $cdr;

    public function __construct(string $cdrDatabaseFile)
    {
        $this->cdr = self::openReadOnly($cdrDatabaseFile);
    }

    public static function openReadOnly(string $file): PDO
    {
        if (!is_file($file)) {
            throw new InvalidArgumentException("Database file not found: $file");
        }
        $pdo = new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Pdo\Sqlite constants exist since PHP 8.4; the PDO::SQLITE_* ones are deprecated in 8.5.
            Sqlite::ATTR_OPEN_FLAGS      => Sqlite::OPEN_READONLY,
        ]);
        $pdo->exec('PRAGMA query_only = 1');
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);

        return $pdo;
    }

    /** 'wal' on local storage; 'delete' (or another rollback mode) on NFS/SMB. */
    public function journalMode(): string
    {
        return strtolower((string)$this->cdr->query('PRAGMA journal_mode')->fetchColumn());
    }

    public function maxId(): int
    {
        return (int)$this->cdr->query('SELECT COALESCE(MAX(id), 0) FROM cdr_general')->fetchColumn();
    }

    /**
     * Legs with an id above $afterId, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function legsAfter(int $afterId, int $limit): array
    {
        $stmt = $this->cdr->prepare('SELECT * FROM cdr_general WHERE id > :id ORDER BY id LIMIT :limit');
        $stmt->bindValue(':id', $afterId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Legs with an id at or below $upToId, newest first (for the back-fill).
     *
     * @return list<array<string, mixed>>
     */
    public function legsUpTo(int $upToId, int $limit): array
    {
        $stmt = $this->cdr->prepare('SELECT * FROM cdr_general WHERE id <= :id ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':id', $upToId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Every leg of the given calls, through the linkedid index.
     *
     * @param list<string> $linkedIds
     *
     * @return list<array<string, mixed>>
     */
    public function legsOfCalls(array $linkedIds): array
    {
        $linkedIds = array_values(array_unique(array_filter($linkedIds, fn ($id): bool => (string)$id !== '')));
        $rows = [];
        // Stay well under SQLite's bound-parameter limit.
        foreach (array_chunk($linkedIds, 200) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->cdr->prepare("SELECT * FROM cdr_general WHERE linkedid IN ($marks) ORDER BY id");
            $stmt->execute($chunk);
            array_push($rows, ...$stmt->fetchAll());
        }

        return $rows;
    }

    /** The day of the oldest leg still in the CDR, or null when it is empty. */
    public function oldestDay(): ?string
    {
        $start = $this->cdr->query('SELECT MIN(start) FROM cdr_general')->fetchColumn();

        return $start === null || $start === false ? null : substr((string)$start, 0, 10);
    }

    /**
     * Fingerprint of the calls whose first leg started on $date: number of
     * calls, number of their legs, highest leg id and CallIndexer::checksum().
     * Read through the start index; one short query per day.
     *
     * @return array{calls: int, legs: int, max_id: int, checksum: int}
     */
    public function dayFingerprint(string $date): array
    {
        $ids = $this->linkedIdsOfDay($date);
        $legs = $this->legsOfCalls($ids);

        return [
            'calls'    => count($ids),
            'legs'     => count($legs),
            'max_id'   => $legs === [] ? 0 : max(array_map(fn (array $l): int => (int)$l['id'], $legs)),
            'checksum' => CallIndexer::checksum($legs),
        ];
    }

    /**
     * linkedids of the calls whose first leg started on $date (as the index
     * dates a call by its first leg).
     *
     * @return list<string>
     */
    public function linkedIdsOfDay(string $date): array
    {
        $next = (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
        $stmt = $this->cdr->prepare(
            'SELECT linkedid, MIN(start) AS first FROM cdr_general
             WHERE linkedid IN (SELECT DISTINCT linkedid FROM cdr_general WHERE start >= :from AND start < :to)
             GROUP BY linkedid HAVING first >= :from AND first < :to'
        );
        $stmt->execute([':from' => $date, ':to' => $next]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * A value from the core's m_PbxSettings, or $default when it is not set
     * (the core then uses its built-in default).
     */
    public static function pbxSetting(string $configDatabaseFile, string $key, string $default): string
    {
        $stmt = self::openReadOnly($configDatabaseFile)->prepare('SELECT value FROM m_PbxSettings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? $default : (string)$value;
    }

    /**
     * Provider IDs (uniqid) from the core's configuration database, used to
     * tell inbound, outbound and internal calls apart.
     *
     * @return list<string>
     */
    public static function providerIds(string $configDatabaseFile): array
    {
        $pdo = self::openReadOnly($configDatabaseFile);

        return array_map('strval', $pdo->query('SELECT uniqid FROM m_Providers')->fetchAll(PDO::FETCH_COLUMN));
    }
}
