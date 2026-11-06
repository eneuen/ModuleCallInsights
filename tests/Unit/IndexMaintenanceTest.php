<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Tests\Unit;

use DateTimeImmutable;
use Modules\ModuleCallInsights\Lib\CallIndexer;
use Modules\ModuleCallInsights\Lib\CallIndexSync;
use Modules\ModuleCallInsights\Lib\CdrReader;
use Modules\ModuleCallInsights\Lib\IndexMaintenance;
use Modules\ModuleCallInsights\Lib\IndexStore;
use Modules\ModuleCallInsights\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Upkeep on a synthetic 40-day CDR (from the recorded call shapes):
 * rebuilds, freezing, purging and the reconcile of edited or deleted calls.
 */
final class IndexMaintenanceTest extends TestCase
{
    private const string LAST_DAY = '2026-11-05';

    private TestDatabases $db;
    private string $cdrFile;
    private string $moduleFile;
    private IndexStore $store;
    private CallIndexer $indexer;

    protected function setUp(): void
    {
        $this->db = new TestDatabases();
        $this->cdrFile = $this->db->tempFile();
        exec(sprintf('%s %s --calls-per-day=20 --days=40 --end-date=%s --out=%s',
            escapeshellarg(PHP_BINARY), escapeshellarg(dirname(__DIR__, 2) . '/tools/benchmark/generate.php'),
            self::LAST_DAY, escapeshellarg($this->cdrFile)), $out, $code);
        self::assertSame(0, $code);
        $this->indexer = new CallIndexer(CdrReader::providerIds($this->cdrFile . '.config.db'));
        @unlink($this->cdrFile . '.config.db');
        $this->moduleFile = $this->db->moduleDatabase();
        $this->store = new IndexStore($this->moduleFile, useWal: true);
        $sync = new CallIndexSync(new CdrReader($this->cdrFile), $this->store, $this->indexer);
        while ($sync->backfillStep() > 0) {
        }
    }

    private function maintenance(int $retentionDays = 360, string $today = '2026-11-06'): IndexMaintenance
    {
        return new IndexMaintenance(new CdrReader($this->cdrFile), $this->store, $this->indexer, $retentionDays,
            new DateTimeImmutable($today));
    }

    private function cdr(): PDO
    {
        return TestDatabases::writable($this->cdrFile);
    }

    private function module(): PDO
    {
        return TestDatabases::writable($this->moduleFile);
    }

    public function testFreshIndexNeedsNoReconcile(): void
    {
        self::assertSame([], $this->maintenance()->reconcile());
        self::assertFalse($this->maintenance()->rebuildIfNeeded());
    }

    public function testReconcileCatchesALegUpdatedInPlace(): void
    {
        // The core rewrote a leg (same id, longer talk time): counts and ids are unchanged.
        $leg = $this->cdr()->query("SELECT id, linkedid, start FROM cdr_general WHERE billsec > 0 AND start LIKE '2026-10-20%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $before = $this->store->call($leg['linkedid']);
        $this->cdr()->exec("UPDATE cdr_general SET billsec = billsec + 100 WHERE id = {$leg['id']}");

        self::assertSame(['2026-10-20'], $this->maintenance()->reconcile());
        self::assertSame($before['talk_seconds'] + 100, $this->store->call($leg['linkedid'])['talk_seconds']);
        self::assertSame([], $this->maintenance()->reconcile(), 'nothing left to fix');
    }

    public function testReconcileRemovesADeletedCall(): void
    {
        $linkedId = $this->cdr()->query("SELECT linkedid FROM cdr_general WHERE start LIKE '2026-10-21%' LIMIT 1")->fetchColumn();
        $callsBefore = array_sum(array_column($this->store->dailyTotals('2026-10-21'), 'calls'));
        $this->cdr()->exec("DELETE FROM cdr_general WHERE linkedid = '$linkedId'");

        self::assertSame(['2026-10-21'], $this->maintenance()->reconcile());
        self::assertNull($this->store->call($linkedId));
        self::assertSame($callsBefore - 1, array_sum(array_column($this->store->dailyTotals('2026-10-21'), 'calls')),
            'the daily totals follow');
    }

    public function testOldLogicVersionTriggersARebuildThatKeepsTotals(): void
    {
        $this->module()->exec('UPDATE m_CallIndex SET logic_version = ' . (CallIndexer::LOGIC_VERSION - 1));
        $this->module()->exec("UPDATE m_DailySummary SET frozen = 1 WHERE local_date = '2026-09-27'");
        $frozen = $this->store->dailyTotals('2026-09-27');

        self::assertTrue($this->maintenance()->rebuildIfNeeded());
        self::assertSame(0, $this->store->callCount());
        self::assertNull($this->store->state(IndexStore::FORWARD_WATERMARK), 'the sync starts over');

        $sync = new CallIndexSync(new CdrReader($this->cdrFile), $this->store, $this->indexer);
        while ($sync->backfillStep() > 0) {
        }
        self::assertFalse($this->store->hasRowsOlderThan(CallIndexer::LOGIC_VERSION));
        self::assertSame($frozen, $this->store->dailyTotals('2026-09-27'), 'frozen totals untouched by the rebuild');
    }

    public function testRebuildButton(): void
    {
        $this->store->setState(IndexStore::REBUILD_REQUESTED, '1');

        self::assertTrue($this->maintenance()->rebuildIfNeeded());
        self::assertNull($this->store->state(IndexStore::REBUILD_REQUESTED), 'the request is consumed');
        self::assertFalse($this->maintenance()->rebuildIfNeeded());
    }

    public function testFreezesTotalsBeforeTheCleanupReachesThem(): void
    {
        // 30-day retention on 2026-11-06: the core deletes days before 2026-10-07;
        // totals freeze 14 days earlier than that, i.e. before 2026-10-21.
        $m = $this->maintenance(30);

        self::assertSame('2026-10-21', $m->freezeBefore());
        self::assertGreaterThan(0, $m->freeze());
        self::assertTrue($this->store->isDayFrozen('2026-10-20'));
        self::assertFalse($this->store->isDayFrozen('2026-10-21'));
        self::assertSame(0, $m->freeze(), 'already frozen');
    }

    public function testUnlimitedRetentionFreezesNothing(): void
    {
        self::assertNull($this->maintenance(0)->freezeBefore());
        self::assertSame(0, $this->maintenance(0)->freeze());
    }

    public function testFrozenDaysAreNotReconciled(): void
    {
        $m = $this->maintenance(30);
        $m->freeze();
        $this->cdr()->exec("UPDATE cdr_general SET billsec = billsec + 100 WHERE start LIKE '2026-10-15%'");

        self::assertNotContains('2026-10-15', $m->reconcile());
    }

    public function testPurgeFollowsTheCdr(): void
    {
        // The core's cleanup removed the first ten days.
        $this->cdr()->exec("DELETE FROM cdr_general WHERE start < '2026-10-07'");
        $oldestRemaining = (new CdrReader($this->cdrFile))->oldestDay();

        self::assertGreaterThan(0, $this->maintenance()->purge());
        [$oldestIndexed] = $this->store->indexedRange();
        self::assertSame($oldestRemaining, $oldestIndexed, 'no index row is older than the CDR');
        self::assertNotEmpty($this->store->dailyTotals('2026-09-30'), 'daily totals of purged days survive');
    }

    public function testMigrateAddsColumnsAnOldDatabaseLacks(): void
    {
        // A database kept from an earlier version, without legs_checksum.
        $old = $this->db->moduleDatabase();
        TestDatabases::writable($old)->exec('ALTER TABLE m_CallIndex DROP COLUMN legs_checksum');
        $store = new IndexStore($old, useWal: true);

        self::assertSame(['m_CallIndex.legs_checksum'], $store->migrate());
        self::assertSame([], $store->migrate(), 'running it again changes nothing');
        self::assertSame([], $this->store->migrate(), 'a current database needs nothing');
    }

    public function testFingerprintsMatchOnAFreshIndex(): void
    {
        $reader = new CdrReader($this->cdrFile);
        foreach (['2026-09-28', '2026-10-15', '2026-11-05'] as $day) {
            self::assertSame($reader->dayFingerprint($day), $this->store->dayFingerprint($day), $day);
        }
    }
}
