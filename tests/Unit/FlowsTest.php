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
 * Call flows (Sankey): inbound paths line -> IVR -> queue -> person ->
 * transfer -> outcome.
 */
final class FlowsTest extends TestCase
{
    private TestDatabases $db;

    protected function setUp(): void
    {
        $this->db = new TestDatabases();
    }

    /**
     * @param list<array{string, string, ?string, ?string, ?string, ?string, string, int, int}> $calls
     *        linkedid, direction, did, ivr_digits, first_queue, answered_by, outcome, ivr_seconds, transfer_count
     */
    private function service(array $calls): QueryService
    {
        $file = $this->db->moduleDatabase();
        $pdo = TestDatabases::writable($file);
        $stmt = $pdo->prepare("INSERT INTO m_CallIndex (linkedid, start_time, local_date, local_hour, weekday, direction,
            did, ivr_digits, first_queue, answered_by, outcome, ivr_seconds, transfer_count)
            VALUES (?, '2026-11-02 10:00:00.000', '2026-11-02', 10, 1, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($calls as $call) {
            $stmt->execute($call);
        }
        return new QueryService(CdrReader::openReadOnly($file));
    }

    /** @return array<string, int> "source > target" => calls */
    private static function links(array $flows): array
    {
        $out = [];
        foreach ($flows['links'] as $l) {
            $out["{$l['source']} > {$l['target']}"] = $l['value'];
        }
        ksort($out);
        return $out;
    }

    public function testPathsSkipStagesACallDidNotReach(): void
    {
        $flows = $this->service([
            // IVR key 1, queue, answered by 302.
            ['a', 'in', '5551000', '1', '3100', '302', 'answered', 8, 0],
            ['b', 'in', '5551000', '1', '3100', '302', 'answered', 6, 0],
            // IVR key 1, queue, nobody answered.
            ['c', 'in', '5551000', '1', '3100', null, 'abandoned_queue', 5, 0],
            // Straight to a person, then transferred.
            ['d', 'in', '5551001', null, null, '301', 'answered', 0, 1],
            // IVR timeout, then voicemail.
            ['e', 'in', '5551001', 't', null, null, 'voicemail', 12, 0],
            // Outbound and internal calls are not part of the flow.
            ['f', 'out', null, null, null, '301', 'answered', 0, 0],
            ['g', 'internal', null, null, null, '302', 'answered', 0, 0],
        ])->flows(['from' => '2026-11-02', 'to' => '2026-11-02']);

        self::assertSame(5, $flows['calls']);
        self::assertSame([
            'ivr:1 > queue:3100'                 => 3,
            'ivr:t > outcome:voicemail'          => 1,
            'line:5551000 > ivr:1'               => 3,
            'line:5551001 > ivr:t'               => 1,
            'line:5551001 > person:301'          => 1,
            'person:301 > transfer:transferred'  => 1,
            'person:302 > outcome:answered'      => 2,
            'queue:3100 > outcome:abandoned_queue' => 1,
            'queue:3100 > person:302'            => 2,
            'transfer:transferred > outcome:answered' => 1,
        ], self::links($flows));
        self::assertSame(['line:5551000', 'line:5551001', 'ivr:1', 'ivr:t', 'queue:3100', 'person:302', 'person:301',
            'transfer:transferred', 'outcome:answered', 'outcome:abandoned_queue', 'outcome:voicemail'],
            array_column($flows['nodes'], 'id'), 'nodes in stage order, busiest first');
    }

    public function testQuietNodesAreMergedIntoOther(): void
    {
        $calls = [];
        // 15 lines: line i has i calls, so lines 1-3 are the quietest.
        for ($line = 1; $line <= 15; $line++) {
            for ($n = 0; $n < $line; $n++) {
                $calls[] = ["c$line-$n", 'in', "55510$line", null, null, '301', 'answered', 0, 0];
            }
        }
        $flows = $this->service($calls)->flows(['from' => '2026-11-02', 'to' => '2026-11-02']);
        $lines = array_values(array_filter($flows['nodes'], fn (array $n): bool => $n['stage'] === 'line'));

        self::assertCount(QueryService::FLOW_NODES_PER_STAGE + 1, $lines);
        self::assertSame(['id' => 'line:@other', 'stage' => 'line', 'key' => '@other', 'value' => 1 + 2 + 3],
            array_values(array_filter($lines, fn (array $n): bool => $n['key'] === '@other'))[0]);
        self::assertSame(6, self::links($flows)['line:@other > person:301']);
    }

    public function testFiltersApplyAndOtherDirectionsAreRefused(): void
    {
        $service = $this->service([
            ['a', 'in', '5551000', null, '3100', '302', 'answered', 0, 0],
            ['b', 'in', '5551001', null, '3200', '303', 'answered', 0, 0],
        ]);
        $flows = $service->flows(['from' => '2026-11-02', 'to' => '2026-11-02', 'filters' => ['queue' => ['3200']]]);
        self::assertSame(1, $flows['calls']);
        self::assertSame(['line:5551001 > queue:3200' => 1, 'person:303 > outcome:answered' => 1, 'queue:3200 > person:303' => 1],
            self::links($flows));

        self::assertSame(2, $service->flows(['from' => '2026-11-02', 'to' => '2026-11-02',
            'filters' => ['direction' => ['in']]])['calls']);
        $this->expectException(InvalidArgumentException::class);
        $service->flows(['from' => '2026-11-02', 'to' => '2026-11-02', 'filters' => ['direction' => ['out']]]);
    }

    public function testFlowIsConservedOnAGeneratedMonth(): void
    {
        $cdr = $this->db->tempFile();
        exec(sprintf('%s %s --calls-per-day=60 --days=30 --end-date=2026-11-05 --out=%s',
            escapeshellarg(PHP_BINARY), escapeshellarg(dirname(__DIR__, 2) . '/tools/benchmark/generate.php'),
            escapeshellarg($cdr)), $out, $code);
        self::assertSame(0, $code);
        $indexer = new CallIndexer(CdrReader::providerIds($cdr . '.config.db'));
        @unlink($cdr . '.config.db');
        $module = $this->db->moduleDatabase();
        $sync = new CallIndexSync(new CdrReader($cdr), new IndexStore($module, useWal: true), $indexer);
        while ($sync->backfillStep() > 0) {
        }
        $service = new QueryService(CdrReader::openReadOnly($module));
        $q = ['from' => '2026-10-07', 'to' => '2026-11-05'];
        $flows = $service->flows($q);

        $inbound = $service->query($q + ['metric' => 'inbound', 'interval' => 'none'])['series'][0]['values'][0];
        self::assertSame($inbound, $flows['calls'], 'every inbound call is in the flow once');
        $in = $outFlow = [];
        foreach ($flows['links'] as $l) {
            $outFlow[$l['source']] = ($outFlow[$l['source']] ?? 0) + $l['value'];
            $in[$l['target']] = ($in[$l['target']] ?? 0) + $l['value'];
        }
        foreach ($flows['nodes'] as $node) {
            if ($node['stage'] !== 'line') {
                self::assertSame($node['value'], $in[$node['id']], "calls into {$node['id']}");
            }
            if ($node['stage'] !== 'outcome') {
                self::assertSame($node['value'], $outFlow[$node['id']], "calls out of {$node['id']}");
            }
        }
        $sum = fn (string $stage): int => array_sum(array_column(
            array_filter($flows['nodes'], fn (array $n): bool => $n['stage'] === $stage), 'value'));
        self::assertSame($flows['calls'], $sum('line'));
        self::assertSame($flows['calls'], $sum('outcome'));
    }

    public function testCatalogMatchesWhatQueriesAccept(): void
    {
        $catalog = QueryService::catalog();
        $service = $this->service([['a', 'in', '5551000', null, null, '301', 'answered', 0, 0]]);
        foreach ($catalog['metrics'] as $m) {
            foreach ($catalog['breakdowns'] as $b) {
                $r = $service->query(['metric' => $m['key'], 'breakdown' => $b, 'interval' => 'none',
                    'from' => '2026-11-02', 'to' => '2026-11-02', 'source' => 'index']);
                self::assertSame($m['key'], $r['metric']);
            }
        }
        self::assertSame(QueryService::metrics(), array_column($catalog['metrics'], 'key'));
        self::assertContains('caller', $catalog['filters']);
    }
}
