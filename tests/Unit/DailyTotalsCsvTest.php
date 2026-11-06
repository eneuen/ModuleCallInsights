<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Tests\Unit;

use InvalidArgumentException;
use Modules\ModuleCallInsights\Lib\DailyTotalsCsv;
use Modules\ModuleCallInsights\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DailyTotalsCsvTest extends TestCase
{
    private TestDatabases $db;

    protected function setUp(): void
    {
        $this->db = new TestDatabases();
    }

    private function totals(array $rows): PDO
    {
        $pdo = TestDatabases::writable($this->db->moduleDatabase());
        $stmt = $pdo->prepare('INSERT INTO m_DailySummary (local_date, direction, did, provider_id, queue, answered_by,
            outcome, calls, answered, talk_seconds, wait_seconds, tta_0_10, frozen) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($rows as $r) {
            $stmt->execute($r);
        }
        return $pdo;
    }

    public function testExportThenImportRestoresLostDays(): void
    {
        $source = $this->totals([
            ['2026-01-05', 'in', '+74950001122', 'SIP-TRUNK-A', '3100', '302', 'answered', 10, 10, 600, 50, 8, 1],
            ['2026-01-05', 'in', '+74950001122', 'SIP-TRUNK-A', '', '', 'no_answer', 2, 0, 0, 0, 0, 1],
            ['2026-01-06', 'out', '', 'SIP-TRUNK-A', '', '', 'answered', 4, 4, 300, 0, 0, 0],
        ]);
        $csv = DailyTotalsCsv::export($source);

        self::assertStringContainsString("'+74950001122", $csv, 'a leading + is protected for spreadsheets');

        // A fresh module (folder wiped) that only has 2026-01-06.
        $target = $this->totals([['2026-01-06', 'out', '', 'SIP-TRUNK-A', '', '', 'answered', 99, 99, 0, 0, 0, 0]]);
        $report = DailyTotalsCsv::import($target, $csv);

        self::assertSame(['days_added' => 1, 'days_skipped' => 1, 'rows_added' => 2], $report);
        $restored = $target->query("SELECT did, calls, frozen FROM m_DailySummary WHERE local_date = '2026-01-05' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([['did' => '+74950001122', 'calls' => 10, 'frozen' => 1], ['did' => '+74950001122', 'calls' => 2, 'frozen' => 1]], $restored,
            'the apostrophe is removed again, and imported days are frozen');
        self::assertSame(99, (int)$target->query("SELECT calls FROM m_DailySummary WHERE local_date = '2026-01-06'")->fetchColumn(),
            'an existing day is never overwritten');
    }

    /** @return iterable<string, array{string}> */
    public static function badFiles(): iterable
    {
        $header = implode(',', DailyTotalsCsv::COLUMNS);
        $ok = '2026-01-05,in,5551000,SIP-TRUNK-A,3100,302,answered,1,1,60,5,1,0,0,0,0,0';
        yield 'wrong header' => ["date,calls\n2026-01-05,1\n"];
        yield 'empty' => [''];
        yield 'missing value' => ["$header\n2026-01-05,in,5551000\n"];
        yield 'bad date' => ["$header\n" . str_replace('2026-01-05', '2026-02-30', $ok) . "\n"];
        yield 'bad direction' => ["$header\n" . str_replace(',in,', ',sideways,', $ok) . "\n"];
        yield 'bad outcome' => ["$header\n" . str_replace(',answered,', ',won,', $ok) . "\n"];
        yield 'negative count' => ["$header\n" . str_replace(',1,1,60,', ',-1,1,60,', $ok) . "\n"];
        yield 'markup in an ID' => ["$header\n" . str_replace(',302,', ',<script>,', $ok) . "\n"];
        yield 'SQL in an ID' => ["$header\n" . str_replace(',3100,', ",'; DROP TABLE m_DailySummary; --,", $ok) . "\n"];
        yield 'one bad line among good ones' => ["$header\n$ok\n" . str_replace(',in,', ',x,', $ok) . "\n"];
    }

    #[DataProvider('badFiles')]
    public function testBadFilesAreRejectedWhole(string $csv): void
    {
        $target = $this->totals([]);
        try {
            DailyTotalsCsv::import($target, $csv);
            self::fail('accepted a bad file');
        } catch (InvalidArgumentException) {
            self::assertSame(0, (int)$target->query('SELECT COUNT(*) FROM m_DailySummary')->fetchColumn(), 'nothing was written');
        }
    }

    public function testProtect(): void
    {
        foreach (['=HYPERLINK("x")', '+7495', '-1', '@SUM(A1)', "\tx", "\rx"] as $v) {
            self::assertSame("'$v", DailyTotalsCsv::protect($v));
        }
        self::assertSame('5551000', DailyTotalsCsv::protect('5551000'));
        self::assertSame('', DailyTotalsCsv::protect(''));
    }
}
