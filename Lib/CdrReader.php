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
        $linkedIds = array_values(array_unique(array_filter($linkedIds, 'strlen')));
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
