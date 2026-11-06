<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Tests\Unit;

use InvalidArgumentException;
use Modules\ModuleCallInsights\Lib\CdrReader;
use Modules\ModuleCallInsights\Tests\Unit\Support\TestDatabases;
use PDOException;
use PHPUnit\Framework\TestCase;

final class CdrReaderTest extends TestCase
{
    private TestDatabases $db;

    protected function setUp(): void
    {
        $this->db = new TestDatabases();
    }

    public function testReadsLegsInBatchesBothWays(): void
    {
        $legs = TestDatabases::legsOf('internal_answered', 'inbound_ivr_key1_queue_answered', 'outbound_answered');
        $reader = new CdrReader($this->db->cdrDatabase($legs));
        $ids = array_column($legs, 'id');
        sort($ids);

        self::assertSame(max($ids), $reader->maxId());
        self::assertSame(array_slice($ids, 0, 3), array_column($reader->legsAfter(0, 3), 'id'));
        self::assertSame(array_slice($ids, 3), array_column($reader->legsAfter($ids[2], 100), 'id'));
        self::assertSame([], $reader->legsAfter(max($ids), 100));
        self::assertSame(array_reverse(array_slice($ids, 0, 4)), array_column($reader->legsUpTo($ids[3], 100), 'id'));
    }

    public function testReadsAllLegsOfCalls(): void
    {
        $legs = TestDatabases::legsOf('inbound_ivr_key1_queue_answered', 'internal_answered');
        $reader = new CdrReader($this->db->cdrDatabase($legs));
        $queueCall = $legs[0]['linkedid'];

        $rows = $reader->legsOfCalls([$queueCall, $queueCall, '']);

        self::assertCount(4, $rows);
        self::assertSame([$queueCall], array_values(array_unique(array_column($rows, 'linkedid'))));
        self::assertSame(array_keys($legs[0]), array_keys($rows[0]), 'every cdr_general column is returned');
        self::assertSame([], $reader->legsOfCalls([]));
    }

    public function testConnectionCannotWrite(): void
    {
        $file = $this->db->cdrDatabase(TestDatabases::legsOf('internal_answered'));
        $pdo = CdrReader::openReadOnly($file);

        $this->expectException(PDOException::class);
        $pdo->exec("UPDATE cdr_general SET src_num = 'changed'");
    }

    public function testReportsJournalMode(): void
    {
        $file = $this->db->cdrDatabase();
        TestDatabases::writable($file)->exec('PRAGMA journal_mode = WAL');

        self::assertSame('wal', (new CdrReader($file))->journalMode());
    }

    public function testReadsProviderIds(): void
    {
        $file = $this->db->configDatabase(['SIP-TRUNK-AAA', 'SIP-BBB']);

        self::assertSame(['SIP-TRUNK-AAA', 'SIP-BBB'], CdrReader::providerIds($file));
    }

    public function testMissingFileIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CdrReader('/nonexistent/cdr.db');
    }
}
