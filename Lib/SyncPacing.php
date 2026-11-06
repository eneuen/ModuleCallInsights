<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Lib;

/**
 * How hard one worker cycle may read the CDR (plan rule 10).
 *
 * The load benchmark (tools/benchmark) measured the unpaced back-fill on a
 * 2-CPU container at 30,000-56,000 legs a second, while concurrent CDR
 * writes waited at most 38 ms. So the back-fill gets a time budget per
 * 60-second cycle, with a short pause after every batch, rather than a
 * fixed number of batches:
 * - With WAL: 500-leg batches, 50 ms pauses.
 * - Without WAL (NFS/SMB), where every read briefly blocks Asterisk's
 *   writes: 200-leg batches, 100 ms pauses and half the time budget. With
 *   a rollback journal the benchmark measured 15 ms per batch (p95) and
 *   concurrent writes waiting at most 14 ms, so the pause only spreads
 *   the reads out; it is not what keeps writes fast.
 *
 * The back-fill speed setting scales only the back-fill; new calls are
 * always indexed first.
 */
final class SyncPacing
{
    public const array SPEEDS = ['slow', 'normal', 'fast'];

    /** Seconds of back-fill per 60-second cycle, on WAL storage. */
    private const array BACKFILL_SECONDS = ['slow' => 5, 'normal' => 20, 'fast' => 40];

    private function __construct(
        /** Legs per CDR read. */
        public readonly int $batchSize,
        /** At most this many forward batches per cycle, so a cycle stays short. */
        public readonly int $maxForwardBatches,
        /** Seconds per cycle the back-fill may use once new calls are indexed. */
        public readonly int $backfillSeconds,
        /** Pause after every batch, in milliseconds. */
        public readonly int $pauseMs,
    ) {
    }

    public static function for(string $cdrJournalMode, string $backfillSpeed): self
    {
        $speed = in_array($backfillSpeed, self::SPEEDS, true) ? $backfillSpeed : 'normal';
        $wal = strtolower($cdrJournalMode) === 'wal';
        $seconds = self::BACKFILL_SECONDS[$speed];

        return new self(
            batchSize: $wal ? 500 : 200,
            maxForwardBatches: 20,
            backfillSeconds: $wal ? $seconds : intdiv($seconds, 2),
            pauseMs: $wal ? 50 : 100,
        );
    }
}
