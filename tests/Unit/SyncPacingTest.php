<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Tests\Unit;

use Modules\ModuleCallInsights\Lib\SyncPacing;
use PHPUnit\Framework\TestCase;

final class SyncPacingTest extends TestCase
{
    public function testNetworkStorageReadsLessAndPausesLonger(): void
    {
        $wal = SyncPacing::for('wal', 'normal');
        $network = SyncPacing::for('delete', 'normal');

        self::assertLessThan($wal->batchSize, $network->batchSize);
        self::assertGreaterThan($wal->pauseMs, $network->pauseMs);
        self::assertLessThan($wal->backfillSeconds, $network->backfillSeconds);
    }

    public function testSpeedScalesOnlyTheBackfill(): void
    {
        $slow = SyncPacing::for('wal', 'slow');
        $fast = SyncPacing::for('wal', 'fast');

        self::assertLessThan($fast->backfillSeconds, $slow->backfillSeconds);
        self::assertLessThan(60, $fast->backfillSeconds, 'a cycle leaves time for the next forward sync');
        self::assertSame($slow->batchSize, $fast->batchSize);
        self::assertSame($slow->maxForwardBatches, $fast->maxForwardBatches);
    }

    public function testUnknownValuesFallBackSafely(): void
    {
        self::assertEquals(SyncPacing::for('wal', 'normal'), SyncPacing::for('WAL', 'turbo'));
        self::assertEquals(SyncPacing::for('delete', 'normal'), SyncPacing::for('', ''), 'unknown journal mode is treated as no WAL');
    }
}
