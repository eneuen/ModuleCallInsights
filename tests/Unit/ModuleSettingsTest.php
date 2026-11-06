<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Tests\Unit;

use InvalidArgumentException;
use Modules\ModuleCallInsights\Lib\IndexStore;
use Modules\ModuleCallInsights\Lib\ModuleSettings;
use Modules\ModuleCallInsights\Tests\Unit\Support\TestDatabases;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleSettingsTest extends TestCase
{
    private const array GOOD = ['service_level_seconds' => '30', 'callback_window_hours' => '48', 'backfill_speed' => 'slow'];

    private TestDatabases $databases;
    private PDO $db;
    private string $file;

    protected function setUp(): void
    {
        $this->databases = new TestDatabases();
        $this->file = $this->databases->moduleDatabase();
        $this->db = TestDatabases::writable($this->file);
    }

    public function testDefaultsWithoutARow(): void
    {
        $this->db->exec('DELETE FROM m_Settings');
        self::assertSame(ModuleSettings::DEFAULTS, ModuleSettings::load($this->db));
    }

    public function testSaveCreatesThenUpdatesOneRow(): void
    {
        $this->db->exec('DELETE FROM m_Settings');
        ModuleSettings::save($this->db, self::GOOD);
        ModuleSettings::save($this->db, ['backfill_speed' => 'fast'] + self::GOOD);

        self::assertSame(1, (int)$this->db->query('SELECT COUNT(*) FROM m_Settings')->fetchColumn());
        self::assertSame(['service_level_seconds' => 30, 'callback_window_hours' => 48, 'backfill_speed' => 'fast'],
            ModuleSettings::load($this->db));
        self::assertSame('fast', (new IndexStore($this->file, useWal: true))->backfillSpeed(), 'the worker sees it');
    }

    public function testOutOfRangeStoredValuesReadAsDefaults(): void
    {
        $this->db->exec('DELETE FROM m_Settings');
        $this->db->exec("INSERT INTO m_Settings (service_level_seconds, callback_window_hours, backfill_speed) VALUES (0, 100000, 'warp')");

        self::assertSame(ModuleSettings::DEFAULTS, ModuleSettings::load($this->db));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function badInput(): iterable
    {
        yield 'missing' => [['backfill_speed' => 'slow']];
        yield 'zero' => [['service_level_seconds' => '0'] + self::GOOD];
        yield 'too large' => [['callback_window_hours' => '721'] + self::GOOD];
        yield 'negative' => [['service_level_seconds' => '-5'] + self::GOOD];
        yield 'decimal' => [['service_level_seconds' => '20.5'] + self::GOOD];
        yield 'text' => [['callback_window_hours' => '24; DROP TABLE m_Settings'] + self::GOOD];
        yield 'list' => [['service_level_seconds' => ['20']] + self::GOOD];
        yield 'unknown speed' => [['backfill_speed' => 'warp'] + self::GOOD];
    }

    #[DataProvider('badInput')]
    public function testBadInputIsRefusedAndNothingSaved(array $input): void
    {
        $before = ModuleSettings::load($this->db);
        try {
            ModuleSettings::save($this->db, $input);
            self::fail('accepted bad input');
        } catch (InvalidArgumentException) {
            self::assertSame($before, ModuleSettings::load($this->db));
        }
    }

    public function testRebuildRequestReachesTheWorker(): void
    {
        self::assertFalse(ModuleSettings::rebuildPending($this->db));
        ModuleSettings::requestRebuild($this->db);
        ModuleSettings::requestRebuild($this->db);

        self::assertTrue(ModuleSettings::rebuildPending($this->db));
        self::assertSame('1', (new IndexStore($this->file, useWal: true))->state(IndexStore::REBUILD_REQUESTED));
    }
}
