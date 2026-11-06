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
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * QueryService on a synthetic month of calls (tools/benchmark/generate.php),
 * indexed with the real sync, and on the recorded fixtures.
 */
final class QueryServiceTest extends TestCase
{
    private const string FROM = '2026-10-07';
    private const string TO = '2026-11-05';

    private static TestDatabases $db;
    private static string $moduleFile;
    private static string $fixtureModuleFile;

    public static function setUpBeforeClass(): void
    {
        self::$db = new TestDatabases();

        // 40 calls a weekday for 30 days, from the recorded call shapes.
        $cdr = self::$db->tempFile();
        $generator = dirname(__DIR__, 2) . '/tools/benchmark/generate.php';
        exec(sprintf('%s %s --calls-per-day=40 --days=30 --end-date=%s --out=%s',
            escapeshellarg(PHP_BINARY), escapeshellarg($generator), self::TO, escapeshellarg($cdr)), $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        self::$moduleFile = self::index($cdr, CdrReader::providerIds($cdr . '.config.db'));
        @unlink($cdr . '.config.db');

        $fixtures = self::$db->cdrDatabase(TestDatabases::legsOf(
            'internal_answered', 'internal_no_answer', 'inbound_direct_answered', 'inbound_queue_abandoned',
            'inbound_ivr_key1_queue_answered', 'inbound_ivr_key3_voicemail', 'outbound_answered', 'inbound_pickup'
        ));
        self::$fixtureModuleFile = self::index($fixtures, [TestDatabases::fixtureProviderId()]);
    }

    /** @return iterable<string, array{string, string, ?string}> */
    public static function dailyAnswerable(): iterable
    {
        foreach (['calls', 'answered', 'missed', 'voicemail', 'inbound', 'outbound', 'talk_seconds',
                     'avg_talk', 'avg_wait', 'answer_rate', 'service_level', 'abandoned', 'busy_failed'] as $metric) {
            foreach (['none', 'day', 'week', 'month'] as $interval) {
                foreach ([null, 'outcome', 'direction', 'did', 'queue', 'answered_by', 'provider'] as $breakdown) {
                    yield "$metric/$interval/" . ($breakdown ?? '-') => [$metric, $interval, $breakdown];
                }
            }
        }
    }

    /**
     * Where both sources can answer, they must give the same numbers.
     */
    #[DataProvider('dailyAnswerable')]
    public function testIndexAndDailyTotalsAgree(string $metric, string $interval, ?string $breakdown): void
    {
        $q = ['metric' => $metric, 'from' => self::FROM, 'to' => self::TO, 'interval' => $interval, 'breakdown' => $breakdown];
        $service = $this->service();

        $index = $service->query($q + ['source' => 'index']);
        $daily = $service->query($q + ['source' => 'daily']);

        self::assertSame('index', $index['source']);
        self::assertSame('daily', $daily['source']);
        self::assertSame($index['buckets'], $daily['buckets']);
        self::assertEquals($index['series'], $daily['series']);
    }

    public function testFiltersAgreeAcrossSources(): void
    {
        $q = ['metric' => 'answered', 'from' => self::FROM, 'to' => self::TO, 'interval' => 'week',
              'breakdown' => 'outcome', 'filters' => ['direction' => ['in'], 'did' => ['5551001', '5551000']]];
        $service = $this->service();

        self::assertEquals($service->query($q + ['source' => 'index'])['series'], $service->query($q + ['source' => 'daily'])['series']);
    }

    public function testTotalsMatchTheIndexer(): void
    {
        // Fixtures: 8 calls, 5 answered (internal, direct, queue key 1, outbound, pickup),
        // 1 voicemail, 1 abandoned queue, 1 internal unanswered.
        $service = new QueryService(self::read(self::$fixtureModuleFile));
        $value = fn (string $metric): mixed => $service->query(
            ['metric' => $metric, 'from' => '2026-11-06', 'to' => '2026-11-06', 'interval' => 'none']
        )['series'][0]['values'][0];

        self::assertSame(8, $value('calls'));
        self::assertSame(5, $value('answered'));
        self::assertSame(1, $value('voicemail'));
        self::assertSame(1, $value('missed'), 'the abandoned queue call; voicemail is not missed');
        self::assertSame(5, $value('inbound'));
        self::assertSame(1, $value('outbound'));
        self::assertEquals(60.0, $value('answer_rate'), '3 of 5 inbound calls answered by a person');
        self::assertSame(1, $value('unique_callers'));
    }

    public function testChoosesTheDailyTotalsForLongRangesOnly(): void
    {
        $service = $this->service();
        $long = ['metric' => 'calls', 'from' => '2026-01-01', 'to' => self::TO, 'interval' => 'month'];

        self::assertSame('daily', $service->query($long)['source']);
        self::assertSame('index', $service->query(['metric' => 'calls', 'from' => self::FROM, 'to' => self::TO])['source']);
        self::assertSame('index', $service->query(['metric' => 'unique_callers'] + $long)['source'], 'not additive');
        self::assertSame('index', $service->query(['metric' => 'calls', 'from' => self::FROM, 'to' => self::TO,
            'interval' => 'none', 'breakdown' => 'hour'])['source'], 'the daily totals have no hours');
    }

    public function testServiceLevelUsesTheIndexForThresholdsBetweenBuckets(): void
    {
        $q = ['metric' => 'service_level', 'from' => '2026-01-01', 'to' => self::TO, 'interval' => 'month'];

        self::assertSame('daily', (new QueryService(self::read(self::$moduleFile), 20))->query($q)['source']);
        self::assertSame('index', (new QueryService(self::read(self::$moduleFile), 25))->query($q)['source']);
        $this->expectException(InvalidArgumentException::class);
        (new QueryService(self::read(self::$moduleFile), 25))->query($q + ['source' => 'daily']);
    }

    public function testCountsAreZeroAndAveragesEmptyOnDaysWithoutCalls(): void
    {
        $service = $this->service();
        $q = ['from' => '2026-11-04', 'to' => '2026-11-10', 'interval' => 'day'];   // last 2 days have no data

        $calls = $service->query($q + ['metric' => 'calls'])['series'][0]['values'];
        $wait = $service->query($q + ['metric' => 'avg_wait'])['series'][0]['values'];

        self::assertCount(7, $calls);
        self::assertSame([0, 0], array_slice($calls, -2));
        self::assertSame([null, null], array_slice($wait, -2));
    }

    public function testWeeksStartOnMonday(): void
    {
        $r = $this->service()->query(['metric' => 'calls', 'from' => '2026-11-01', 'to' => '2026-11-05', 'interval' => 'week']);

        self::assertSame(['2026-10-26', '2026-11-02'], $r['buckets'], 'Sunday 1 Nov belongs to the week of Monday 26 Oct');
    }

    public function testHeatmapByWeekdayAndHour(): void
    {
        $r = $this->service()->query(['metric' => 'calls', 'from' => self::FROM, 'to' => self::TO,
            'interval' => 'hour', 'breakdown' => 'weekday', 'filters' => ['direction' => ['in']]]);

        self::assertSame('index', $r['source']);
        self::assertNotEmpty($r['buckets']);
        self::assertLessThanOrEqual(7, count($r['series']));
    }

    public function testInjectionAttemptsAreDataNotSql(): void
    {
        $r = $this->service()->query(['metric' => 'calls', 'from' => self::FROM, 'to' => self::TO, 'interval' => 'none',
            'filters' => ['did' => ["' OR 1=1 --", '5551000") UNION SELECT 1 --']]]);

        self::assertSame([], $r['series'], 'no call matches those values');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidQueries(): iterable
    {
        $ok = ['metric' => 'calls', 'from' => self::FROM, 'to' => self::TO];
        yield 'unknown metric' => [['metric' => 'calls; DROP TABLE m_CallIndex'] + $ok];
        yield 'unknown breakdown' => [['breakdown' => 'caller_num'] + $ok];
        yield 'unknown interval' => [['interval' => 'minute'] + $ok];
        yield 'unknown filter' => [['filters' => ['caller_num' => ['1']]] + $ok];
        yield 'filter value not text' => [['filters' => ['did' => [['x']]]] + $ok];
        yield 'too many filter values' => [['filters' => ['did' => range(1, 51)]] + $ok];
        yield 'filters not a map' => [['filters' => 'did=1'] + $ok];
        yield 'bad date' => [['from' => '2026-13-01'] + $ok];
        yield 'NUL byte in a date' => [['from' => "2026-10-01\0"] + $ok];
        yield 'date with time' => [['to' => '2026-11-05 10:00'] + $ok];
        yield 'from after to' => [['from' => '2026-11-06', 'to' => '2026-11-05'] + $ok];
        yield 'range too long' => [['from' => '2010-01-01'] + $ok];
        yield 'hourly range too long' => [['interval' => 'hour', 'from' => '2026-01-01'] + $ok];
        yield 'unknown source' => [['source' => 'cdr'] + $ok];
        yield 'daily source for an index-only metric' => [['metric' => 'unique_callers', 'source' => 'daily'] + $ok];
    }

    /** @param array<string, mixed> $q */
    #[DataProvider('invalidQueries')]
    public function testInvalidQueriesAreRefused(array $q): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->query($q);
    }

    public function testDrillDownPagesMatchTheChart(): void
    {
        $service = $this->service();
        $q = ['from' => self::FROM, 'to' => self::TO, 'filters' => ['direction' => ['in'], 'outcome' => ['answered']]];
        $chart = $service->query($q + ['metric' => 'calls', 'interval' => 'none'])['series'][0]['values'][0];

        $first = $service->calls($q, 1);
        $second = $service->calls($q, 2);

        self::assertSame($chart, $first['total'], 'the list holds exactly the calls the chart counted');
        self::assertCount(QueryService::PAGE_SIZE, $first['calls']);
        self::assertSame([], array_intersect(array_column($first['calls'], 'linkedid'), array_column($second['calls'], 'linkedid')));
        self::assertGreaterThanOrEqual($first['calls'][1]['start_time'], $first['calls'][0]['start_time'], 'newest first');
        foreach ($first['calls'] as $call) {
            self::assertSame(['in', 'answered'], [$call['direction'], $call['outcome']]);
        }
    }

    public function testHeatmapCellDrillDown(): void
    {
        $service = $this->service();
        $cell = ['from' => self::FROM, 'to' => self::TO, 'filters' => ['weekday' => ['2'], 'hour' => ['10']]];
        $list = $service->calls($cell);

        self::assertGreaterThan(0, $list['total']);
        foreach ($list['calls'] as $call) {
            self::assertSame('10', substr($call['start_time'], 11, 2));
            self::assertSame('2', (new \DateTimeImmutable(substr($call['start_time'], 0, 10)))->format('N'));
        }
        self::assertSame('index', $service->query($cell + ['metric' => 'calls', 'interval' => 'day', 'from' => '2026-01-01'])['source'],
            'hour and weekday filters need the index even for long ranges');
    }

    public function testDrillDownRefusesBadPages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->calls(['from' => self::FROM, 'to' => self::TO], 0);
    }

    public function testTeamCountsRingsAndAnswersPerExtension(): void
    {
        // Fixtures: 302 rang 5 times (internal answered and unanswered, abandoned
        // queue, queue key 1, pickup) and answered 3; 303 rang twice, answered none;
        // 301 answered the direct call, rang unanswered in the pickup, and made
        // the outbound call.
        $team = array_column((new QueryService(self::read(self::$fixtureModuleFile)))
            ->team(['from' => '2026-11-06', 'to' => '2026-11-06']), null, 'extension');

        self::assertSame([5, 3, 2], [$team['302']['rang'], $team['302']['answered'], $team['302']['unanswered']]);
        self::assertSame([2, 0, 2], [$team['303']['rang'], $team['303']['answered'], $team['303']['unanswered']]);
        self::assertSame([2, 1, 1], [$team['301']['rang'], $team['301']['answered'], $team['301']['outbound']]);
        self::assertEquals(60.0, $team['302']['answer_rate']);
        self::assertNull($team['303']['avg_ring'], 'no answered rings to average');
        self::assertArrayNotHasKey('5551234567', $team, 'outside numbers are not team members');
    }

    public function testTeamDrillDownToUnansweredRings(): void
    {
        $service = new QueryService(self::read(self::$fixtureModuleFile));
        $list = $service->calls(['from' => '2026-11-06', 'to' => '2026-11-06', 'filters' => ['rang_unanswered' => ['302']]]);

        self::assertSame(2, $list['total'], 'the two calls that rang 302 without an answer from 302');
    }

    public function testTeamRefusesFiltersItCannotApply(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->team(['from' => self::FROM, 'to' => self::TO, 'filters' => ['outcome' => ['answered']]]);
    }

    public function testConcurrencyPeaksByHour(): void
    {
        $r = $this->service()->concurrency(['from' => self::FROM, 'to' => self::TO]);

        self::assertCount(24, $r['by_hour']);
        self::assertSame(max($r['by_hour']), $r['peak']);
        self::assertSame(0, $r['by_hour'][3], 'nobody calls at 3 a.m. in the office profile');
        self::assertGreaterThan(0, $r['by_hour'][11]);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $r['peak_at']);
    }

    public function testConcurrencyCountsOverlaps(): void
    {
        $file = self::$db->moduleDatabase();
        $pdo = \Modules\ModuleCallInsights\Tests\Unit\Support\TestDatabases::writable($file);
        foreach ([['a', '10:00:00', '10:05:00'], ['b', '10:01:00', '10:02:00'], ['c', '10:01:30', '10:03:00'], ['d', '10:05:00', '10:06:00']] as [$id, $s, $e]) {
            $pdo->exec("INSERT INTO m_CallIndex (linkedid, start_time, end_time, local_date, local_hour, weekday, direction, outcome)
                VALUES ('$id', '2026-11-02 $s.000', '2026-11-02 $e.000', '2026-11-02', 10, 1, 'in', 'answered')");
        }
        $r = (new QueryService(self::read($file)))->concurrency(['from' => '2026-11-02', 'to' => '2026-11-02']);

        self::assertSame(3, $r['peak'], 'a, b and c overlap; d starts as a ends');
        self::assertSame('2026-11-02 10:01', $r['peak_at']);
    }

    public function testConcurrencyRangeIsLimited(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->concurrency(['from' => '2026-01-01', 'to' => self::TO]);
    }

    public function testTableAgreesWithSingleQueries(): void
    {
        $service = $this->service();
        $q = ['from' => self::FROM, 'to' => self::TO, 'breakdown' => 'did', 'filters' => ['direction' => ['in']]];
        $table = $service->table($q, ['inbound', 'answered', 'missed', 'answer_rate']);

        $answered = $service->query($q + ['metric' => 'answered', 'interval' => 'none']);
        $byDid = array_column($table['rows'], 'answered', 'key');
        foreach ($answered['series'] as $s) {
            self::assertSame($s['values'][0], $byDid[$s['key']]);
        }
        foreach ($table['rows'] as $row) {
            self::assertSame(['key', 'inbound', 'answered', 'missed', 'answer_rate'], array_keys($row));
        }
    }

    public function testIvrFunnelNarrows(): void
    {
        $t = (new QueryService(self::read(self::$fixtureModuleFile)))
            ->table(['from' => '2026-11-06', 'to' => '2026-11-06'], ['ivr_entered', 'ivr_routed', 'ivr_keyed', 'ivr_answered']);
        $row = $t['rows'][0];

        // Fixtures with the IVR: abandoned queue (timed out to the queue), key 1 (answered),
        // key 3 (voicemail; no digits recorded). All three got past the IVR.
        self::assertSame(3, $row['ivr_entered']);
        self::assertSame(3, $row['ivr_routed']);
        self::assertSame(1, $row['ivr_answered']);
        self::assertLessThanOrEqual($row['ivr_routed'], $row['ivr_answered'], 'each funnel stage is a subset');
        self::assertSame(1, $row['ivr_keyed'], 'timeouts are not key presses; voicemail records no digits');
    }

    public function testWaitBands(): void
    {
        $t = $this->service()->table(['from' => self::FROM, 'to' => self::TO, 'breakdown' => 'wait_band',
            'filters' => ['direction' => ['in'], 'outcome' => ['answered']]], ['calls']);
        $bands = array_column($t['rows'], 'calls', 'key');

        self::assertArrayNotHasKey('', $bands, 'every answered inbound call has a band');
        self::assertSame(
            $this->service()->query(['metric' => 'answered', 'from' => self::FROM, 'to' => self::TO, 'interval' => 'none',
                'filters' => ['direction' => ['in']]])['series'][0]['values'][0],
            array_sum($bands)
        );
    }

    public function testTableLimitsMetrics(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->table(['from' => self::FROM, 'to' => self::TO], []);
    }

    public function testPreviousPeriod(): void
    {
        self::assertSame(['2026-10-25', '2026-10-31'], QueryService::previousPeriod('2026-11-01', '2026-11-07'));
        self::assertSame(['2026-02-28', '2026-02-28'], QueryService::previousPeriod('2026-03-01', '2026-03-01'));
    }

    private function service(): QueryService
    {
        return new QueryService(self::read(self::$moduleFile));
    }

    private static function read(string $file): PDO
    {
        return CdrReader::openReadOnly($file);
    }

    /** @param list<string> $providers */
    private static function index(string $cdrFile, array $providers): string
    {
        $module = self::$db->moduleDatabase();
        $store = new IndexStore($module, useWal: true);
        $sync = new CallIndexSync(new CdrReader($cdrFile), $store, new CallIndexer($providers));
        while ($sync->backfillStep() > 0) {
        }
        return $module;
    }
}
