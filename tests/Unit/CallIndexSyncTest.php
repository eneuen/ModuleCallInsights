<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Tests\Unit;

use Modules\ModuleCallInsights\Lib\CallIndexer;
use Modules\ModuleCallInsights\Lib\CallIndexSync;
use Modules\ModuleCallInsights\Lib\CdrReader;
use Modules\ModuleCallInsights\Lib\IndexStore;
use Modules\ModuleCallInsights\Tests\Unit\Support\TestDatabases;
use PHPUnit\Framework\TestCase;

/**
 * The sync against production-like databases filled with every recorded
 * fixture: back-fill, forward sync, late legs, re-runs and daily totals.
 */
final class CallIndexSyncTest extends TestCase
{
    private const array ALL_SCENARIOS = [
        'internal_answered', 'internal_no_answer', 'internal_rejected', 'inbound_direct_answered',
        'inbound_ivr_timeout_queue_answered', 'inbound_queue_abandoned', 'outbound_answered', 'outbound_no_answer',
        'inbound_ivr_key1_queue_answered', 'inbound_ivr_key2_no_answer', 'inbound_ivr_key3_voicemail',
        'inbound_blind_transfer_refer', 'inbound_blind_transfer_feature', 'inbound_attended_transfer',
        'inbound_pickup', 'inbound_missed_then_callback',
    ];

    private TestDatabases $db;
    private CallIndexer $indexer;

    protected function setUp(): void
    {
        $this->db = new TestDatabases();
        $this->indexer = new CallIndexer([TestDatabases::fixtureProviderId()]);
    }

    public function testBackfillIndexesEveryCallLikeTheIndexerDoes(): void
    {
        $legs = TestDatabases::legsOf(...self::ALL_SCENARIOS);
        [$sync, $store] = $this->sync($this->db->cdrDatabase($legs), batch: 3);

        self::assertSame(0, $sync->syncForward(), 'nothing is newer than the starting watermark');
        $batches = 0;
        while ($sync->backfillStep() > 0) {
            $batches++;
        }

        self::assertTrue($sync->backfillDone());
        self::assertGreaterThan(5, $batches, 'small batches really were used');
        $calls = self::byCall($legs);
        self::assertSame(count($calls), $store->callCount());
        foreach ($calls as $linkedId => $callLegs) {
            self::assertSame($this->indexer->index($callLegs), self::withoutId($store->call($linkedId)), $linkedId);
            self::assertEquals($this->indexer->agents($callLegs), $store->agentsOf((string)$linkedId), "$linkedId agents");
        }
    }

    public function testForwardSyncPicksUpNewCalls(): void
    {
        $cdr = $this->db->cdrDatabase(TestDatabases::legsOf('internal_answered'));
        [$sync, $store] = $this->sync($cdr);
        $sync->initialise();

        TestDatabases::insertLegs(TestDatabases::writable($cdr), TestDatabases::legsOf('inbound_pickup', 'outbound_answered'));
        while ($sync->syncForward() > 0) {
        }

        self::assertSame(2, $store->callCount(), 'the two new calls; the old one is left to the back-fill');
        $newest = max(array_column(TestDatabases::legsOf('inbound_pickup', 'outbound_answered'), 'id'));
        self::assertSame((string)$newest, $store->state(IndexStore::FORWARD_WATERMARK));
    }

    public function testLateLegUpdatesItsCall(): void
    {
        // The queue call's legs arrive minutes apart; here the answered agent's leg comes last.
        $legs = TestDatabases::legsOf('inbound_ivr_key1_queue_answered');
        usort($legs, fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        $answeredLeg = array_values(array_filter($legs, fn (array $l): bool => (int)$l['billsec'] > 0))[0];
        $early = array_values(array_filter($legs, fn (array $l): bool => $l['id'] !== $answeredLeg['id']));

        $cdr = $this->db->cdrDatabase();
        [$sync, $store] = $this->sync($cdr);
        $sync->initialise();

        TestDatabases::insertLegs(TestDatabases::writable($cdr), $early);
        $sync->syncForward();
        $before = $store->call($legs[0]['linkedid']);

        // A late leg always gets a higher id: cdr_general.id is AUTOINCREMENT.
        $answeredLeg['id'] = max(array_column($legs, 'id')) + 1;
        TestDatabases::insertLegs(TestDatabases::writable($cdr), [$answeredLeg]);
        $sync->syncForward();
        $after = $store->call($legs[0]['linkedid']);

        self::assertSame(3, $before['leg_count']);
        self::assertNotSame('answered', $before['outcome']);
        self::assertSame(4, $after['leg_count']);
        self::assertSame('answered', $after['outcome']);
        self::assertSame('302', $after['answered_by']);
        self::assertSame(1, $store->callCount(), 'updated in place, not duplicated');
    }

    public function testRerunningGivesTheSameResult(): void
    {
        $cdr = $this->db->cdrDatabase(TestDatabases::legsOf(...self::ALL_SCENARIOS));
        [$sync, $store] = $this->sync($cdr);
        while ($sync->backfillStep() > 0) {
        }
        $first = array_map(fn (string $id): ?array => $store->call($id), array_keys(self::byCall(TestDatabases::legsOf(...self::ALL_SCENARIOS))));
        $totals = $store->dailyTotals('2026-11-06');

        $store->setState(IndexStore::BACKFILL_CURSOR, $store->state(IndexStore::FORWARD_WATERMARK));
        while ($sync->backfillStep() > 0) {
        }

        self::assertSame($first, array_map(fn (string $id): ?array => $store->call($id), array_keys(self::byCall(TestDatabases::legsOf(...self::ALL_SCENARIOS)))));
        self::assertSame(self::withoutIds($totals), self::withoutIds($store->dailyTotals('2026-11-06')));
    }

    public function testDailyTotalsAddUp(): void
    {
        $legs = TestDatabases::legsOf(...self::ALL_SCENARIOS);
        [$sync, $store] = $this->sync($this->db->cdrDatabase($legs));
        while ($sync->backfillStep() > 0) {
        }

        $calls = array_map(fn (array $l): array => $this->indexer->index($l), array_values(self::byCall($legs)));
        $answered = array_filter($calls, fn (array $c): bool => $c['outcome'] === 'answered');
        $timed = array_filter($answered, fn (array $c): bool => $c['wait_seconds'] !== null);
        $totals = $store->dailyTotals('2026-11-06');

        self::assertSame(count($calls), array_sum(array_column($totals, 'calls')));
        self::assertSame(count($answered), array_sum(array_column($totals, 'answered')));
        self::assertSame(array_sum(array_column($calls, 'talk_seconds')), array_sum(array_column($totals, 'talk_seconds')));
        $bucketed = 0;
        foreach (array_keys(IndexStore::TTA_BUCKETS) as $bucket) {
            $bucketed += array_sum(array_column($totals, $bucket));
        }
        // Outbound calls have no time to answer, so only timed answered calls are bucketed.
        self::assertLessThan(count($answered), count($timed), 'the fixtures include answered outbound calls');
        self::assertSame(count($timed), $bucketed, 'every timed answered call falls in exactly one bucket');
        foreach ($totals as $row) {
            self::assertNotContains('5550000100', $row, 'daily totals hold no caller numbers');
        }
    }

    public function testFrozenDayIsNeverRecomputed(): void
    {
        $cdr = $this->db->cdrDatabase();
        $module = $this->db->moduleDatabase();
        $pdo = TestDatabases::writable($module);
        $pdo->exec("INSERT INTO m_DailySummary (local_date, direction, outcome, calls, frozen) VALUES ('2026-11-06', 'in', 'answered', 99, 1)");
        [$sync, $store] = $this->sync($cdr, moduleFile: $module);
        $sync->initialise();

        TestDatabases::insertLegs(TestDatabases::writable($cdr), TestDatabases::legsOf('inbound_direct_answered'));
        $sync->syncForward();

        self::assertSame(1, $store->callCount(), 'the call is still indexed');
        $totals = $store->dailyTotals('2026-11-06');
        self::assertCount(1, $totals);
        self::assertSame(99, $totals[0]['calls'], 'the frozen totals are kept as they were');
    }

    public function testEmptyCdrIsHandled(): void
    {
        [$sync, $store] = $this->sync($this->db->cdrDatabase());

        self::assertSame(0, $sync->syncForward());
        self::assertSame(0, $sync->backfillStep());
        self::assertTrue($sync->backfillDone());
        self::assertSame(0, $store->callCount());
    }

    /** @return array{CallIndexSync, IndexStore} */
    private function sync(string $cdrFile, int $batch = CallIndexSync::DEFAULT_BATCH, ?string $moduleFile = null): array
    {
        $store = new IndexStore($moduleFile ?? $this->db->moduleDatabase(), useWal: true);

        return [new CallIndexSync(new CdrReader($cdrFile), $store, $this->indexer, $batch), $store];
    }

    /**
     * @param list<array<string, mixed>> $legs
     * @return array<string, list<array<string, mixed>>>
     */
    private static function byCall(array $legs): array
    {
        $calls = [];
        foreach ($legs as $leg) {
            $calls[$leg['linkedid']][] = $leg;
        }
        return $calls;
    }

    /** @param array<string, mixed>|null $row */
    private static function withoutId(?array $row): ?array
    {
        if ($row !== null) {
            unset($row['id']);
        }
        return $row;
    }

    /** @param list<array<string, mixed>> $rows */
    private static function withoutIds(array $rows): array
    {
        return array_map(fn (array $r): ?array => self::withoutId($r), $rows);
    }
}
