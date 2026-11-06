<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Tests\Unit;

use DateTimeImmutable;
use InvalidArgumentException;
use Modules\ModuleCallInsights\Lib\SavedReports;
use Modules\ModuleCallInsights\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SavedReportsTest extends TestCase
{
    private const array GOOD = [
        'metric' => 'answer_rate', 'breakdown' => 'queue', 'interval' => 'week', 'chart' => 'line',
        'preset' => '90', 'compare' => true, 'filters' => ['direction' => ['in'], 'did' => ['5551000', '5551000']],
    ];

    private TestDatabases $databases;
    private PDO $db;

    protected function setUp(): void
    {
        $this->databases = new TestDatabases();
        $this->db = TestDatabases::writable($this->databases->moduleDatabase());
    }

    private static function json(array $d): string
    {
        return json_encode($d, JSON_THROW_ON_ERROR);
    }

    public function testSaveListReplaceAndDelete(): void
    {
        $saved = SavedReports::save($this->db, '  Queue answer rate  ', self::json(self::GOOD + ['extra' => '<b>x</b>']),
            new DateTimeImmutable('2026-11-06 10:00:00'));

        self::assertSame('Queue answer rate', $saved['name'], 'name trimmed');
        self::assertArrayNotHasKey('extra', $saved['definition'], 'unknown keys are dropped');
        self::assertSame(['did' => ['5551000'], 'direction' => ['in']], $saved['definition']['filters'], 'deduplicated and sorted');

        $again = SavedReports::save($this->db, 'Queue answer rate', self::json(['chart' => 'table'] + self::GOOD));
        self::assertSame($saved['id'], $again['id'], 'same name replaces');
        SavedReports::save($this->db, 'Aardvark', self::json(['preset' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'] + self::GOOD));

        $all = SavedReports::all($this->db);
        self::assertSame(['Aardvark', 'Queue answer rate'], array_column($all, 'name'));
        self::assertSame('table', $all[1]['definition']['chart']);
        self::assertSame('2026-10-01', $all[0]['definition']['from']);

        self::assertTrue(SavedReports::delete($this->db, (string)$saved['id']));
        self::assertFalse(SavedReports::delete($this->db, (string)$saved['id']), 'already gone');
        self::assertSame(['Aardvark'], array_column(SavedReports::all($this->db), 'name'));
    }

    public function testATamperedRowIsSkippedNotTrusted(): void
    {
        SavedReports::save($this->db, 'Good', self::json(self::GOOD));
        $this->db->exec("INSERT INTO m_SavedReport (name, definition, updated_at) VALUES
            ('Bad metric', '{\"metric\":\"COUNT(*); DROP TABLE x\",\"breakdown\":\"\",\"interval\":\"day\",\"chart\":\"line\",\"preset\":\"30\"}', '2026-11-06'),
            ('Not JSON', '<script>', '2026-11-06')");

        self::assertSame(['Good'], array_column(SavedReports::all($this->db), 'name'));
    }

    public function testTheReportLimit(): void
    {
        $stmt = $this->db->prepare("INSERT INTO m_SavedReport (name, definition, updated_at) VALUES (?, '{}', '2026-11-06')");
        for ($i = 0; $i < SavedReports::MAX_REPORTS; $i++) {
            $stmt->execute(["r$i"]);
        }
        SavedReports::save($this->db, 'r5', self::json(self::GOOD));      // replacing one is still fine
        $this->expectException(InvalidArgumentException::class);
        SavedReports::save($this->db, 'one more', self::json(self::GOOD));
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function badInput(): iterable
    {
        $j = static fn (array $d): string => json_encode($d);
        yield 'empty name' => ['   ', $j(self::GOOD)];
        yield 'long name' => [str_repeat('x', 81), $j(self::GOOD)];
        yield 'control char in name' => ["a\u{0007}b", $j(self::GOOD)];
        yield 'name not a string' => [['x'], $j(self::GOOD)];
        yield 'not JSON' => ['r', '{metric:'];
        yield 'not an object' => ['r', '"calls"'];
        yield 'too long' => ['r', str_repeat(' ', 5000)];
        yield 'deep' => ['r', str_repeat('[', 20) . str_repeat(']', 20)];
        yield 'unknown metric' => ['r', $j(['metric' => 'secret'] + self::GOOD)];
        yield 'SQL metric' => ['r', $j(['metric' => 'COUNT(*)'] + self::GOOD)];
        yield 'unknown breakdown' => ['r', $j(['breakdown' => 'caller_num'] + self::GOOD)];
        yield 'unknown interval' => ['r', $j(['interval' => 'year'] + self::GOOD)];
        yield 'unknown chart' => ['r', $j(['chart' => 'radar'] + self::GOOD)];
        yield 'unknown preset' => ['r', $j(['preset' => '1000'] + self::GOOD)];
        yield 'custom without dates' => ['r', $j(['preset' => 'custom'] + self::GOOD)];
        yield 'impossible date' => ['r', $j(['preset' => 'custom', 'from' => '2026-02-30', 'to' => '2026-03-01'] + self::GOOD)];
        yield 'NUL byte in a date' => ['r', $j(['preset' => 'custom', 'from' => "2026-03-01\0", 'to' => '2026-03-02'] + self::GOOD)];
        yield 'dates reversed' => ['r', $j(['preset' => 'custom', 'from' => '2026-03-02', 'to' => '2026-03-01'] + self::GOOD)];
        yield 'unknown filter' => ['r', $j(['filters' => ['linkedid' => ['1']]] + self::GOOD)];
        yield 'filter not a list' => ['r', $j(['filters' => ['did' => '5551000']] + self::GOOD)];
        yield 'filter with markup' => ['r', $j(['filters' => ['did' => ['<img src=x onerror=alert(1)>']]] + self::GOOD)];
        yield 'too many filter values' => ['r', $j(['filters' => ['did' => array_map('strval', range(1, 21))]] + self::GOOD)];
    }

    #[DataProvider('badInput')]
    public function testBadInputIsRefusedAndNothingSaved(mixed $name, mixed $definition): void
    {
        try {
            SavedReports::save($this->db, $name, $definition);
            self::fail('accepted bad input');
        } catch (InvalidArgumentException) {
            self::assertSame(0, (int)$this->db->query('SELECT COUNT(*) FROM m_SavedReport')->fetchColumn());
        }
    }

    public function testDeleteRefusesOddIds(): void
    {
        foreach (['0', '-1', '1 OR 1=1', 'abc', ''] as $id) {
            try {
                SavedReports::delete($this->db, $id);
                self::fail("accepted id $id");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
