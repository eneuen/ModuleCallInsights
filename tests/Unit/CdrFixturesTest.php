<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checks that every recorded CDR fixture is complete and well-formed, so the
 * call-indexer tests built on them start from trustworthy data.
 */
final class CdrFixturesTest extends TestCase
{
    private const string DIR = __DIR__ . '/../fixtures/cdr';

    /** The 30 columns of cdr_general in MikoPBX 2026.6.34. */
    private const array COLUMNS = [
        'id', 'start', 'endtime', 'answer', 'src_chan', 'src_num', 'src_name', 'dst_chan', 'dst_num',
        'dst_name', 'UNIQUEID', 'linkedid', 'did', 'disposition', 'recordingfile', 'from_account',
        'to_account', 'dialstatus', 'appname', 'transfer', 'is_app', 'duration', 'billsec',
        'work_completed', 'src_call_id', 'dst_call_id', 'verbose_call_id', 'a_transfer',
        'rec_src_channel', 'dtmf_digits',
    ];

    /** @return iterable<string, array{string}> */
    public static function fixtureFiles(): iterable
    {
        foreach (glob(self::DIR . '/*.json') ?: [] as $file) {
            yield basename($file, '.json') => [$file];
        }
    }

    public function testFixturesExist(): void
    {
        self::assertNotEmpty(glob(self::DIR . '/*.json'));
    }

    #[DataProvider('fixtureFiles')]
    public function testFixtureIsWellFormed(string $file): void
    {
        $fixture = json_decode((string)file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(basename($file, '.json'), $fixture['scenario']);
        self::assertNotEmpty($fixture['description']);
        self::assertMatchesRegularExpression('/^\d{4}\.\d+\.\d+/', $fixture['mikopbx_version']);
        self::assertNotEmpty($fixture['legs']);

        $uniqueIds = [];
        foreach ($fixture['legs'] as $leg) {
            self::assertSame(self::COLUMNS, array_keys($leg), 'every leg has exactly the cdr_general columns');
            self::assertNotEmpty($leg['linkedid']);
            self::assertSame('1', $leg['work_completed'], 'only finished legs reach cdr_general');
            $uniqueIds[] = $leg['UNIQUEID'];
        }
        self::assertSame($uniqueIds, array_unique($uniqueIds), 'no leg is recorded twice');
    }
}
