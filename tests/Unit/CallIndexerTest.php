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

        // Forwarding after 8 s unanswered: the caller waited through both rings.
        yield 'forwarded to an extension' => ['inbound_forward_no_answer_to_extension', [$inDirect + [
            'outcome' => 'answered', 'answered_by' => '302', 'wait_seconds' => 12, 'talk_seconds' => 4,
            'transfer_count' => 0, 'leg_count' => 2]]];
        // A forward to the employee's mobile leaves through the trunk but stays an
        // inbound call; the mobile number is labelled with the employee's name.
        yield 'forwarded to a mobile' => ['inbound_forward_no_answer_to_external', [$inDirect + [
            'outcome' => 'answered', 'answered_by' => '5551234567', 'wait_seconds' => 12, 'talk_seconds' => 4,
            'transfer_count' => 0]]];

        // The consultation call to the queue comes from 301, so it is not the call's queue.
        yield 'attended transfer to a queue, answered' => ['inbound_attended_transfer_to_queue_answered', [$inDirect + [
            'outcome' => 'answered', 'answered_by' => '301', 'first_queue' => null, 'transfer_count' => 1,
            'talk_seconds' => 16, 'leg_count' => 5]]];
        yield 'attended transfer to a queue, cancelled' => ['inbound_attended_transfer_to_queue_cancelled', [$inDirect + [
            'outcome' => 'answered', 'answered_by' => '301', 'first_queue' => null, 'transfer_count' => 0,
            'talk_seconds' => 17]]];
        // MikoPBX ends the caller's call when the transferer hangs up while the queue rings.
        yield 'attended transfer to a queue, transferer leaves' => ['inbound_attended_transfer_to_queue_transferer_leaves', [$inDirect + [
            'outcome' => 'answered', 'answered_by' => '301', 'first_queue' => null, 'transfer_count' => 0,
            'talk_seconds' => 9]]];

        // Time parked is on hold, not talk: 6 s with 301 and 4 s with 302.
        yield 'parked, picked up by 302' => ['inbound_parked_retrieved', [$inDirect + [
            'outcome' => 'answered', 'answered_by' => '301', 'transfer_count' => 1, 'talk_seconds' => 10,
            'leg_count' => 3]]];
        // The channel-less leg of the comeback must not become the start of the call.
        yield 'parked, timed out back to 301' => ['inbound_parked_timeout', [$inDirect + [
            'outcome' => 'answered', 'answered_by' => '301', 'wait_seconds' => 2, 'transfer_count' => 0,
            'talk_seconds' => 6]]];

        yield 'conference room' => ['inbound_conference', [[
            'direction' => 'in', 'did' => '5551002', 'caller_num' => '5550000100', 'callee_num' => '5551002',
            'outcome' => 'answered', 'answered_by' => '3300', 'wait_seconds' => 0, 'talk_seconds' => 10,
            'transfer_count' => 0]]];
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

    /**
     * Who each recorded call rang, and who answered (extension => answered).
     *
     * @return iterable<string, array{string, array<string, int>}>
     */
    public static function agentExpectations(): iterable
    {
        yield 'queue, 302 answers' => ['inbound_ivr_key1_queue_answered', ['302' => 1, '303' => 0]];
        yield 'queue after IVR timeout, 303 answers' => ['inbound_ivr_timeout_queue_answered', ['302' => 0, '303' => 1]];
        yield 'queue abandoned: both rang' => ['inbound_queue_abandoned', ['302' => 0, '303' => 0]];
        yield 'direct' => ['inbound_direct_answered', ['301' => 1]];
        yield 'IVR key 2, unanswered' => ['inbound_ivr_key2_no_answer', ['304' => 0]];
        yield 'voicemail rings nobody' => ['inbound_ivr_key3_voicemail', []];
        yield 'pickup: 301 rang, 302 picked up' => ['inbound_pickup', ['301' => 0, '302' => 1]];
        yield 'blind transfer' => ['inbound_blind_transfer_refer', ['301' => 1, '302' => 1]];
        yield 'attended transfer, consult leg left out' => ['inbound_attended_transfer', ['301' => 1, '302' => 1]];
        yield 'internal answered' => ['internal_answered', ['302' => 1]];
        yield 'internal unanswered' => ['internal_no_answer', ['302' => 0]];
        yield 'outbound rings nobody inside' => ['outbound_answered', []];
        yield 'forwarded: 301 rang, 302 answered' => ['inbound_forward_no_answer_to_extension', ['301' => 0, '302' => 1]];
        yield 'forwarded to a mobile: only 301 rang inside' => ['inbound_forward_no_answer_to_external', ['301' => 0]];
        yield 'attended transfer to a queue: consult rings left out' => ['inbound_attended_transfer_to_queue_answered', ['301' => 1, '302' => 1]];
        yield 'parked: the slot is not a person' => ['inbound_parked_retrieved', ['301' => 1, '302' => 1]];
        yield 'parked timeout: back to 301' => ['inbound_parked_timeout', ['301' => 1]];
        yield 'conference room is not a person' => ['inbound_conference', []];
    }

    /** @param array<string, int> $expected */
    #[DataProvider('agentExpectations')]
    public function testAgents(string $scenario, array $expected): void
    {
        $fixture = self::load($scenario);
        $agents = (new CallIndexer([$fixture['setup']['provider_id']]))->agents($fixture['legs']);

        self::assertSame($expected, array_column($agents, 'answered', 'extension'));
        foreach ($agents as $agent) {
            self::assertGreaterThanOrEqual(0, $agent['ring_seconds']);
            self::assertSame($agent['answered'] === 1, $agent['talk_seconds'] > 0);
        }
    }

    public function testALegThatReachedNoPhoneIsNotARing(): void
    {
        // As recorded on a misconfigured forward: 301 rings out, then the PBX tries an
        // extension with nothing registered (201) and a mobile it never dials out.
        // Neither leg has a destination channel, and neither is a person on the Team page.
        $fixture = self::load('inbound_forward_no_answer_to_external');
        [$rang, $forwarded] = $fixture['legs'];
        $noPhone = ['id' => 9001, 'dst_num' => '201', 'dst_chan' => '', 'to_account' => '', 'disposition' => 'NOANSWER',
                    'billsec' => 0, 'answer' => '', 'UNIQUEID' => 'test-no-phone'] + $forwarded;
        $notDialled = ['id' => 9002, 'dst_num' => '89261111111', 'dst_chan' => '', 'to_account' => '', 'disposition' => 'NOANSWER',
                       'billsec' => 0, 'answer' => '', 'UNIQUEID' => 'test-not-dialled'] + $forwarded;

        $indexer = new CallIndexer([$fixture['setup']['provider_id']]);
        $agents = $indexer->agents([$rang, $noPhone, $notDialled]);

        self::assertSame(['301' => 0], array_column($agents, 'answered', 'extension'));
        self::assertSame('no_answer', $indexer->index([$rang, $noPhone, $notDialled])['outcome'], 'still a missed call');
    }

    public function testAgentRingTimeEndsAtTheAnswer(): void
    {
        $agents = (new CallIndexer([self::load('inbound_direct_answered')['setup']['provider_id']]))
            ->agents(self::load('inbound_direct_answered')['legs']);

        self::assertSame(3, $agents[0]['ring_seconds'], '301 answered after 3 s');
        self::assertSame(5, $agents[0]['talk_seconds']);
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
