<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Lib;

/**
 * How hard one worker cycle may read the CDR (plan rule 10).
 *
 * With WAL, reads don't block Asterisk's writes but long ones stall the
 * core's checkpoint. Without WAL (NFS/SMB storage), every read briefly
 * blocks Asterisk's writes, so batches are smaller and pauses longer.
 * The back-fill speed setting scales only the back-fill; new calls are
 * always indexed first.
 */
final class SyncPacing
{
    public const array SPEEDS = ['slow', 'normal', 'fast'];

    private const array BACKFILL_BATCHES = ['slow' => 2, 'normal' => 10, 'fast' => 40];

    private function __construct(
        /** Legs per CDR read. */
        public readonly int $batchSize,
        /** At most this many forward batches per cycle, so a cycle stays short. */
        public readonly int $maxForwardBatches,
        /** Back-fill batches per cycle once the forward sync is up to date. */
        public readonly int $backfillBatches,
        /** Pause between two batches, in milliseconds. */
        public readonly int $pauseMs,
    ) {
    }

    public static function for(string $cdrJournalMode, string $backfillSpeed): self
    {
        $speed = in_array($backfillSpeed, self::SPEEDS, true) ? $backfillSpeed : 'normal';
        $wal = strtolower($cdrJournalMode) === 'wal';

        return new self(
            batchSize: $wal ? 500 : 200,
            maxForwardBatches: 20,
            backfillBatches: self::BACKFILL_BATCHES[$speed],
            pauseMs: $wal ? 200 : 1000,
        );
    }
}
