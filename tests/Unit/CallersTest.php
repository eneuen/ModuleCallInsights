<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Tests\Unit;

use InvalidArgumentException;
use Modules\ModuleCallInsights\Lib\CallIndexer;
use Modules\ModuleCallInsights\Lib\CallIndexSync;
use Modules\ModuleCallInsights\Lib\CdrReader;
use Modules\ModuleCallInsights\Lib\IndexStore;
use Modules\ModuleCallInsights\Lib\QueryService;
use Modules\ModuleCallInsights\Tests\Unit\Support\TestDatabases;
use PHPUnit\Framework\TestCase;

/**
 * The Callers page: callbacks, the call-back list, top and new callers.
 */
final class CallersTest extends TestCase
{
    private TestDatabases $db;

    protected function setUp(): void
    {
        $this->db = new TestDatabases();
    }

    /**
     * @param list<array{string, string, string, string, string, string}> $calls
     *        linkedid, start, direction, caller, callee, outcome
     */
    private function service(array $calls): QueryService
    {
        $file = $this->db->moduleDatabase();
        $pdo = TestDatabases::writable($file);
        $stmt = $pdo->prepare('INSERT INTO m_CallIndex (linkedid, start_time, local_date, local_hour, weekday, direction,
            caller_num, callee_num, outcome) VALUES (?, ?, ?, 10, 1, ?, ?, ?, ?)');
        foreach ($calls as [$id, $start, $direction, $caller, $callee, $outcome]) {
            $stmt->execute([$id, $start, substr($start, 0, 10), $direction, $caller, $callee, $outcome]);
        }
        return new QueryService(CdrReader::openReadOnly($file));
    }

    public function testCallbacksAndTheCallBackList(): void
    {
        $service = $this->service([
            // A: missed, called back an hour later.
            ['a1', '2026-11-02 10:00:00.000', 'in', '555000001', '5551000', 'no_answer'],
            ['a2', '2026-11-02 11:00:00.000', 'out', '301', '555000001', 'answered'],
            // B: missed, never contacted.
            ['b1', '2026-11-02 12:00:00.000', 'in', '555000002', '5551000', 'abandoned_queue'],
            // C: missed, rang in again two days later (outside a 24 h window).
            ['c1', '2026-11-02 09:00:00.000', 'in', '555000003', '5551000', 'no_answer'],
            ['c2', '2026-11-04 09:00:00.000', 'in', '555000003', '5551000', 'answered'],
            // D: voicemail, never contacted.
            ['d1', '2026-11-03 15:00:00.000', 'in', '555000004', '5551000', 'voicemail'],
            // E: missed, then an unanswered attempt to call back.
            ['e1', '2026-11-03 16:00:00.000', 'in', '555000005', '5551000', 'no_answer'],
            ['e2', '2026-11-03 16:05:00.000', 'out', '302', '555000005', 'no_answer'],
            // An answered call that needs no callback.
            ['f1', '2026-11-03 17:00:00.000', 'in', '555000006', '5551000', 'answered'],
        ]);

        $r = $service->callers(['from' => '2026-11-02', 'to' => '2026-11-04'], 24);

        self::assertSame(5, $r['missed_calls'], 'A, B, C, D and E');
        self::assertSame(1, $r['called_back'], 'only A within the window');
        self::assertEquals(20.0, $r['callback_rate']);
        self::assertSame(3600, $r['avg_callback_seconds']);
        self::assertSame(['555000005', '555000004', '555000002'], array_column($r['not_returned'], 'number'),
            'newest first; C was answered later, so it is not on the list');
        self::assertSame('voicemail', $r['not_returned'][1]['last_outcome']);
    }

    public function testWiderWindowCountsLaterCallbacks(): void
    {
        $service = $this->service([
            ['c1', '2026-11-02 09:00:00.000', 'in', '555000003', '5551000', 'no_answer'],
            ['c2', '2026-11-04 09:00:00.000', 'in', '555000003', '5551000', 'answered'],
        ]);

        self::assertSame(0, $service->callers(['from' => '2026-11-02', 'to' => '2026-11-04'], 24)['called_back']);
        self::assertSame(1, $service->callers(['from' => '2026-11-02', 'to' => '2026-11-04'], 72)['called_back']);
    }

    public function testNewAndReturningCallers(): void
    {
        $service = $this->service([
            ['o1', '2026-10-01 10:00:00.000', 'in', '555000010', '5551000', 'answered'],   // seen before the range
            ['o2', '2026-11-02 10:00:00.000', 'in', '555000010', '5551000', 'answered'],
            ['n1', '2026-11-02 11:00:00.000', 'in', '555000011', '5551000', 'answered'],   // first ever call
            ['n2', '2026-11-03 11:00:00.000', 'in', '555000011', '5551000', 'no_answer'],
        ]);
        $r = $service->callers(['from' => '2026-11-01', 'to' => '2026-11-07'], 24);

        self::assertSame([1, 1], [$r['new_callers'], $r['returning_callers']]);
        self::assertSame('555000011', $r['top_callers'][0]['number']);
        self::assertSame([2, 1, 1], [$r['top_callers'][0]['calls'], $r['top_callers'][0]['answered'], $r['top_callers'][0]['missed']]);
    }

    public function testRecordedMissedCallAndCallback(): void
    {
        $cdr = $this->db->cdrDatabase(TestDatabases::legsOf('inbound_missed_then_callback'));
        $module = $this->db->moduleDatabase();
        $store = new IndexStore($module, useWal: true);
        $sync = new CallIndexSync(new CdrReader($cdr), $store, new CallIndexer([TestDatabases::fixtureProviderId()]));
        while ($sync->backfillStep() > 0) {
        }
        $r = (new QueryService(CdrReader::openReadOnly($module)))->callers(['from' => '2026-11-06', 'to' => '2026-11-06'], 24);

        self::assertSame([1, 1], [$r['missed_calls'], $r['called_back']]);
        self::assertSame([], $r['not_returned']);
        self::assertLessThan(60, $r['avg_callback_seconds'], '301 called back within seconds');
    }

    public function testWindowIsValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service([])->callers(['from' => '2026-11-01', 'to' => '2026-11-02'], 0);
    }
}
