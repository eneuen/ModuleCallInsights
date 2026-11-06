<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Tests\Unit;

use InvalidArgumentException;
use Modules\ModuleCallInsights\Lib\CallIndexer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CallIndexer against the CDR fixtures recorded from real calls
 * (tests/fixtures/cdr), plus edge cases the recordings don't cover.
 *
 * The expected values were checked by hand against each scenario: who
 * answered, how long the caller waited and talked, and the outcome.
 */
final class CallIndexerTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../fixtures/cdr';

    /**
     * Expected index fields per fixture call. Fixtures with two calls list
     * them in start order.
     *
     * @return iterable<string, array{string, list<array<string, int|string|null>>}>
     */
    public static function fixtureExpectations(): iterable
    {
        $inDirect = ['direction' => 'in', 'did' => '5551001', 'caller_num' => '5550000100', 'callee_num' => '5551001'];
        $inMain = ['direction' => 'in', 'did' => '5551000', 'caller_num' => '5550000100', 'callee_num' => '5551000'];
        $internal = ['direction' => 'internal', 'did' => null, 'provider_id' => null, 'caller_num' => '301', 'callee_num' => '302'];
        $out = ['direction' => 'out', 'did' => null, 'answered_by' => null, 'wait_seconds' => null,
                'caller_num' => '301', 'callee_num' => '5551234567'];

        yield 'internal answered' => ['internal_answered', [$internal + [
            'outcome' => 'answered', 'answered_by' => '302', 'wait_seconds' => 3, 'talk_seconds' => 4,
            'leg_count' => 1, 'transfer_count' => 0]]];
        yield 'internal no answer' => ['internal_no_answer', [$internal + [
            'outcome' => 'no_answer', 'answered_by' => null, 'wait_seconds' => null, 'talk_seconds' => 0]]];
        yield 'internal rejected' => ['internal_rejected', [$internal + [
            'outcome' => 'no_answer', 'answered_by' => null, 'talk_seconds' => 0]]];

        yield 'inbound direct answered' => ['inbound_direct_answered', [$inDirect + [
            'outcome' => 'answered', 'answered_by' => '301', 'wait_seconds' => 3, 'talk_seconds' => 5,
            'ivr_seconds' => 0, 'first_queue' => null, 'transfer_count' => 0]]];

        yield 'IVR timeout to queue, answered' => ['inbound_ivr_timeout_queue_answered', [$inMain + [
            'outcome' => 'answered', 'answered_by' => '303', 'first_queue' => '3100', 'ivr_digits' => 't,t',
            'ivr_seconds' => 15, 'wait_seconds' => 17, 'talk_seconds' => 5, 'leg_count' => 4, 'transfer_count' => 0]]];
        yield 'IVR key 1 to queue, answered' => ['inbound_ivr_key1_queue_answered', [$inMain + [
            'outcome' => 'answered', 'answered_by' => '302', 'first_queue' => '3100', 'ivr_digits' => '1',
            'ivr_seconds' => 3, 'wait_seconds' => 6, 'talk_seconds' => 5, 'leg_count' => 4]]];
        yield 'IVR key 2, unanswered' => ['inbound_ivr_key2_no_answer', [$inMain + [
            'outcome' => 'no_answer', 'answered_by' => null, 'ivr_digits' => '2', 'first_queue' => null,
            'talk_seconds' => 0]]];
        // MikoPBX records no dtmf_digits when the key leads to voicemail.
        yield 'IVR key 3 to voicemail' => ['inbound_ivr_key3_voicemail', [$inMain + [
            'outcome' => 'voicemail', 'answered_by' => null, 'wait_seconds' => null, 'talk_seconds' => 0,
            'ivr_digits' => null]]];
        yield 'queue abandoned' => ['inbound_queue_abandoned', [$inMain + [
            'outcome' => 'abandoned_queue', 'answered_by' => null, 'first_queue' => '3100',
            'talk_seconds' => 0, 'leg_count' => 4]]];

        yield 'blind transfer by REFER' => ['inbound_blind_transfer_refer', [$inDirect + [
            'outcome' => 'answered', 'answered_by' => '301', 'transfer_count' => 1, 'talk_seconds' => 7,
            'leg_count' => 2]]];
        yield 'blind transfer by **' => ['inbound_blind_transfer_feature', [$inDirect + [
            'outcome' => 'answered', 'answered_by' => '301', 'transfer_count' => 1, 'talk_seconds' => 9]]];
        // The consult leg (301 -> 302) is not talk time with the caller.
        yield 'attended transfer by ##' => ['inbound_attended_transfer', [$inDirect + [
            'outcome' => 'answered', 'answered_by' => '301', 'transfer_count' => 1, 'talk_seconds' => 14,
            'leg_count' => 3]]];
        // to_account still says 301 on the picked-up leg; the answerer is dst_num.
        yield 'pickup with *8' => ['inbound_pickup', [$inDirect + [
            'outcome' => 'answered', 'answered_by' => '302', 'transfer_count' => 0, 'talk_seconds' => 5]]];

        yield 'missed then callback' => ['inbound_missed_then_callback', [
            $inDirect + ['outcome' => 'no_answer', 'answered_by' => null, 'talk_seconds' => 0],
            ['direction' => 'out', 'outcome' => 'answered', 'caller_num' => '301', 'callee_num' => '5550000100',
             'talk_seconds' => 4, 'did' => null],
        ]];

        yield 'outbound answered' => ['outbound_answered', [$out + ['outcome' => 'answered', 'talk_seconds' => 6]]];
        yield 'outbound no answer' => ['outbound_no_answer', [$out + ['outcome' => 'no_answer', 'talk_seconds' => 0]]];
    }

    /**
     * @param list<array<string, int|string|null>> $expectedCalls
     */
    #[DataProvider('fixtureExpectations')]
    public function testFixture(string $scenario, array $expectedCalls): void
    {
        $fixture = self::load($scenario);
        $providerId = $fixture['setup']['provider_id'];
        $indexer = new CallIndexer([$providerId]);

        $calls = [];
        foreach ($fixture['legs'] as $leg) {
            $calls[$leg['linkedid']][] = $leg;
        }
        $rows = array_map(fn (array $legs): array => $indexer->index($legs), array_values($calls));
        usort($rows, fn (array $a, array $b): int => strcmp($a['start_time'], $b['start_time']));

        self::assertCount(count($expectedCalls), $rows, 'number of calls');
        foreach ($expectedCalls as $i => $expected) {
            $row = $rows[$i];
            if (array_key_exists('provider_id', $expected) && $expected['provider_id'] === null) {
                self::assertNull($row['provider_id']);
                unset($expected['provider_id']);
            } elseif ($row['direction'] !== 'internal') {
                self::assertSame($providerId, $row['provider_id'], 'provider');
            }
            foreach ($expected as $field => $value) {
                self::assertSame($value, $row[$field], "$scenario: $field");
            }
            self::assertSame(CallIndexer::LOGIC_VERSION, $row['logic_version']);
            self::assertSame(substr($row['start_time'], 0, 10), $row['local_date']);
        }
    }

    /**
     * The built-in call history marks a call ANSWERED when any of its legs is
     * ANSWERED (PBXCoreREST/Lib/Cdr/GetListAction.php). The index must agree
     * on every call, except that voicemail is kept apart: the voicemail leg is
     * ANSWERED, but no person spoke to the caller. Checked live against the
     * REST API on MikoPBX 2026.6.34 as well: 16 of 17 calls agree, the
     * voicemail call being the expected difference.
     */
    #[DataProvider('fixtureExpectations')]
    public function testAnsweredAgreesWithTheBuiltInCallHistory(string $scenario): void
    {
        $fixture = self::load($scenario);
        $indexer = new CallIndexer([$fixture['setup']['provider_id']]);
        $calls = [];
        foreach ($fixture['legs'] as $leg) {
            $calls[$leg['linkedid']][] = $leg;
        }
        foreach ($calls as $legs) {
            $coreAnswered = in_array('ANSWERED', array_column($legs, 'disposition'), true);
            $outcome = $indexer->index($legs)['outcome'];
            if ($outcome === CallIndexer::OUTCOME_VOICEMAIL) {
                self::assertTrue($coreAnswered, 'the core counts voicemail as answered');
                continue;
            }
            self::assertSame($coreAnswered, $outcome === CallIndexer::OUTCOME_ANSWERED, $scenario);
        }
    }

    public function testEveryFixtureIsCovered(): void
    {
        $covered = array_map(fn (array $c): string => $c[0], iterator_to_array(self::fixtureExpectations()));
        $files = array_map(fn (string $f): string => basename($f, '.json'), glob(self::FIXTURES . '/*.json'));
        self::assertEqualsCanonicalizing($files, array_values($covered));
    }

    public function testDuplicateLegIsCountedOnce(): void
    {
        $legs = self::load('internal_answered')['legs'];
        $copy = $legs[0];
        $copy['id'] = $legs[0]['id'] + 1000;      // the same UNIQUEID copied twice
        $row = (new CallIndexer([]))->index([...$legs, $copy]);

        self::assertSame(1, $row['leg_count']);
        self::assertSame(4, $row['talk_seconds']);
        self::assertSame($copy['id'], $row['max_leg_id'], 'the newer copy is kept');
    }

    public function testLegOrderDoesNotMatter(): void
    {
        $legs = self::load('inbound_ivr_key1_queue_answered')['legs'];
        $indexer = new CallIndexer([self::load('inbound_ivr_key1_queue_answered')['setup']['provider_id']]);

        self::assertSame($indexer->index($legs), $indexer->index(array_reverse($legs)));
    }

    public function testDeletedProviderIsStillRecognised(): void
    {
        // The trunk is no longer in the Providers table, but its calls stay inbound/outbound.
        $inbound = (new CallIndexer([]))->index(self::load('inbound_direct_answered')['legs']);
        $outbound = (new CallIndexer([]))->index(self::load('outbound_answered')['legs']);

        self::assertSame('in', $inbound['direction']);
        self::assertSame(self::load('inbound_direct_answered')['setup']['provider_id'], $inbound['provider_id']);
        self::assertSame('out', $outbound['direction']);
        self::assertSame('internal', (new CallIndexer([]))->index(self::load('internal_answered')['legs'])['direction']);
    }

    public function testBusyAndFailedOutcomes(): void
    {
        $leg = self::load('internal_no_answer')['legs'][0];
        $indexer = new CallIndexer([]);

        self::assertSame('busy', $indexer->index([['dialstatus' => 'BUSY', 'disposition' => 'BUSY'] + $leg])['outcome']);
        self::assertSame('failed', $indexer->index([['dialstatus' => 'CHANUNAVAIL'] + $leg])['outcome']);
    }

    public function testOutOfHours(): void
    {
        $leg = self::load('inbound_direct_answered')['legs'][0];
        $leg = ['dst_chan' => 'App:outworktimes', 'dst_num' => 'outworktimes', 'answer' => '',
                'billsec' => 0, 'disposition' => 'NOANSWER', 'dialstatus' => ''] + $leg;
        $row = (new CallIndexer([self::load('inbound_direct_answered')['setup']['provider_id']]))->index([$leg]);

        self::assertSame('out_of_hours', $row['outcome']);
    }

    public function testTimeWithoutMillisecondsAndWeekday(): void
    {
        $leg = ['start' => '2026-11-08 23:59:59', 'answer' => '2026-11-09 00:00:04', 'endtime' => '2026-11-09 00:00:10',
                'billsec' => 6] + self::load('internal_answered')['legs'][0];
        $row = (new CallIndexer([]))->index([$leg]);

        self::assertSame('2026-11-08', $row['local_date']);
        self::assertSame(23, $row['local_hour']);
        self::assertSame(7, $row['weekday'], '2026-11-08 is a Sunday');
        self::assertSame(5, $row['wait_seconds']);
        self::assertSame(11, $row['total_seconds']);
    }

    public function testNoLegsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CallIndexer([]))->index([]);
    }

    public function testLegsOfDifferentCallsAreRefused(): void
    {
        $legs = self::load('inbound_missed_then_callback')['legs'];
        $this->expectException(InvalidArgumentException::class);
        (new CallIndexer([]))->index($legs);
    }

    /** @return array<string, mixed> */
    private static function load(string $scenario): array
    {
        return json_decode((string)file_get_contents(self::FIXTURES . "/$scenario.json"), true, flags: JSON_THROW_ON_ERROR);
    }
}
