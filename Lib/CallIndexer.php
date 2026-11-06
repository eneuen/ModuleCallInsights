<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Lib;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Turns the CDR legs of one call (one linkedid) into one call-index row.
 *
 * This is where every metric is defined. The rules follow what MikoPBX
 * actually writes, as recorded in tests/fixtures/cdr (see its README):
 * - IVR, queue and voicemail legs are recognised by dst_chan
 *   ("App:<ivr>", "Queue:<queue>", "VOICEMAIL"), never by appname.
 * - transfer and a_transfer are always 0, so transfers are counted from
 *   the people who answered the caller.
 * - a voicemail leg looks answered (ANSWERED, billsec > 0) but is not a
 *   person; neither are IVR and queue legs.
 * - disposition alone is not the outcome: agents rung by an abandoned
 *   queue call have dialstatus ANSWERED but billsec 0.
 * - a parked call has a "Park:<slot>" leg whose billsec is the time spent
 *   parked: on hold, not talk, and not a person.
 * - a conference room ("MeetMe:<room>") answers the call but is not a
 *   person, so it is never on the Team page.
 * - a parked call that times out back to its parker can have a leg with
 *   no channels whose start, rounded to the second, comes before the
 *   call's real first leg; a call starts at its first leg with a source
 *   channel.
 * - the consultation call of an attended transfer starts from the
 *   transferring extension, so a queue only it reached is not the call's
 *   queue.
 *
 * Pure PHP with no core dependencies, so it is unit-tested against the
 * recorded fixtures without a PBX. The result has the CallIndex columns
 * (except id).
 */
final class CallIndexer
{
    /** Bump when a definition changes; rows built with an older version are rebuilt. */
    public const int LOGIC_VERSION = 5;

    public const string DIRECTION_IN = 'in';
    public const string DIRECTION_OUT = 'out';
    public const string DIRECTION_INTERNAL = 'internal';

    public const string OUTCOME_ANSWERED = 'answered';
    public const string OUTCOME_VOICEMAIL = 'voicemail';
    /** Inbound: the caller hung up in the IVR, before reaching a person or a queue. */
    public const string OUTCOME_ABANDONED_IVR = 'abandoned_ivr';
    /** Inbound: the caller hung up while waiting in a queue. */
    public const string OUTCOME_ABANDONED_QUEUE = 'abandoned_queue';
    /** Inbound: the call ended in out-of-hours handling. */
    public const string OUTCOME_OUT_OF_HOURS = 'out_of_hours';
    public const string OUTCOME_BUSY = 'busy';
    /** Not connected: congestion, unavailable or an invalid number. */
    public const string OUTCOME_FAILED = 'failed';
    /** Rang, nobody answered (and none of the above). */
    public const string OUTCOME_NO_ANSWER = 'no_answer';

    private const string KIND_PERSON = 'person';
    private const string KIND_IVR = 'ivr';
    private const string KIND_QUEUE = 'queue';
    private const string KIND_VOICEMAIL = 'voicemail';
    private const string KIND_OUT_OF_HOURS = 'out_of_hours';
    private const string KIND_PARKED = 'parked';
    private const string KIND_CONFERENCE = 'conference';

    private const array FAILED_STATUSES = ['CHANUNAVAIL', 'CONGESTION', 'INVALIDARGS', 'FAILED'];

    /** @var array<string, true> */
    private array $providers;

    /**
     * @param list<string> $providerIds Provider IDs from the Providers table
     *                                  (e.g. "SIP-TRUNK-9B90C86F"). IDs of deleted
     *                                  providers are still recognised by their form.
     */
    public function __construct(array $providerIds)
    {
        $this->providers = array_fill_keys(array_filter($providerIds, fn ($id): bool => (string)$id !== ''), true);
    }

    /**
     * @param list<array<string, mixed>> $legs cdr_general rows sharing one linkedid.
     *
     * @return array<string, int|string|null> One CallIndex row (without id).
     */
    public function index(array $legs): array
    {
        $legs = $this->prepare($legs);
        $first = $this->firstLeg($legs);

        $direction = $this->direction($legs, $first);

        // Who talked to the caller, in order: people (and conference rooms) on
        // the caller's side with talk time. Consult legs of an attended
        // transfer start from the transferring extension and are left out.
        $answered = array_values(array_filter(
            $legs,
            fn (array $l): bool => in_array($l['kind'], [self::KIND_PERSON, self::KIND_CONFERENCE], true)
                && (int)$l['billsec'] > 0
                && $this->onCallerSide($l, $first)
        ));

        // Channel-less legs carry a rounded start, so the call's start comes from the others.
        $timed = array_filter($legs, fn (array $l): bool => (string)$l['src_chan'] !== '') ?: $legs;
        $start = min(array_column($timed, 'start_ts'));
        $end = max(array_column($legs, 'end_ts'));

        $firstAnswer = $answered[0] ?? null;
        $trunkLeg = $direction === self::DIRECTION_OUT ? $this->trunkLeg($legs) : null;

        return [
            'linkedid'       => (string)$first['linkedid'],
            'start_time'     => (string)$first['start'],
            'end_time'       => $this->latestEnd($legs),
            'local_date'     => substr((string)$first['start'], 0, 10),
            'local_hour'     => (int)substr((string)$first['start'], 11, 2),
            'weekday'        => (int)(new DateTimeImmutable(substr((string)$first['start'], 0, 10)))->format('N'),
            'direction'      => $direction,
            'did'            => $this->firstNonEmpty($legs, 'did'),
            'provider_id'    => $this->providerOf($first, $trunkLeg, $direction),
            'first_queue'    => $this->firstQueue($legs, $first),
            'ivr_digits'     => $this->ivrDigits($legs),
            'answered_by'    => $direction === self::DIRECTION_OUT ? null : ($firstAnswer['dst_num'] ?? null),
            'outcome'        => $this->outcome($direction, $legs, $answered, $trunkLeg),
            'ivr_seconds'    => $this->seconds($legs, self::KIND_IVR),
            'wait_seconds'   => $firstAnswer === null || $direction === self::DIRECTION_OUT
                ? null
                : (int)round($firstAnswer['answer_ts'] - $start),
            'talk_seconds'   => $direction === self::DIRECTION_OUT
                ? (int)($trunkLeg['billsec'] ?? 0)
                : array_sum(array_map(fn (array $l): int => (int)$l['billsec'], $answered)),
            'total_seconds'  => (int)round($end - $start),
            'leg_count'      => count($legs),
            'transfer_count' => max(0, count(array_unique(array_column($answered, 'dst_num'))) - 1),
            'recorded'       => $this->recorded($legs) ? 1 : 0,
            'caller_num'     => (string)$first['src_num'],
            'callee_num'     => $this->callee($direction, $first, $trunkLeg),
            'max_leg_id'     => max(array_map(fn (array $l): int => (int)$l['id'], $legs)),
            'legs_checksum'  => self::checksum($legs),
            'logic_version'  => self::LOGIC_VERSION,
        ];
    }

    /**
     * De-duplicates by UNIQUEID (keeping the newest row), classifies each leg
     * and sorts by start time.
     *
     * @param list<array<string, mixed>> $legs
     *
     * @return list<array<string, mixed>>
     */
    private function prepare(array $legs): array
    {
        if ($legs === []) {
            throw new InvalidArgumentException('A call needs at least one leg.');
        }
        $byUniqueId = [];
        foreach ($legs as $leg) {
            $key = (string)($leg['UNIQUEID'] ?? '') !== '' ? (string)$leg['UNIQUEID'] : 'id:' . $leg['id'];
            if (!isset($byUniqueId[$key]) || (int)$leg['id'] > (int)$byUniqueId[$key]['id']) {
                $byUniqueId[$key] = $leg;
            }
        }
        $linkedIds = array_unique(array_column($byUniqueId, 'linkedid'));
        if (count($linkedIds) !== 1) {
            throw new InvalidArgumentException('All legs of a call must share one linkedid.');
        }

        $prepared = [];
        foreach ($byUniqueId as $leg) {
            $leg['kind'] = $this->kind($leg);
            $leg['start_ts'] = self::timestamp((string)$leg['start']);
            $leg['end_ts'] = self::timestamp((string)($leg['endtime'] ?: $leg['start']));
            $leg['answer_ts'] = $leg['answer'] !== '' && $leg['answer'] !== null
                ? self::timestamp((string)$leg['answer'])
                : null;
            $prepared[] = $leg;
        }
        usort($prepared, fn (array $a, array $b): int => [$a['start_ts'], (int)$a['id']] <=> [$b['start_ts'], (int)$b['id']]);

        return $prepared;
    }

    /**
     * The leg the call starts with: the earliest one with a source channel.
     * Legs come sorted by start time; a channel-less leg (written for a
     * parked call that times out) can carry an earlier, rounded start.
     */
    private function firstLeg(array $legs): array
    {
        foreach ($legs as $leg) {
            if ((string)$leg['src_chan'] !== '') {
                return $leg;
            }
        }
        return $legs[0];
    }

    /** A leg on the caller's side of the call: from the caller's channel or number. */
    private function onCallerSide(array $leg, array $first): bool
    {
        return $leg['src_chan'] === $first['src_chan'] || $leg['src_num'] === $first['src_num'];
    }

    private function kind(array $leg): string
    {
        $dst = (string)$leg['dst_chan'];
        return match (true) {
            $dst === 'VOICEMAIL'            => self::KIND_VOICEMAIL,
            $dst === 'App:outworktimes'     => self::KIND_OUT_OF_HOURS,
            str_starts_with($dst, 'Queue:') => self::KIND_QUEUE,
            str_starts_with($dst, 'Park:')  => self::KIND_PARKED,
            str_starts_with($dst, 'MeetMe:'), str_starts_with($dst, 'ConfBridge:') => self::KIND_CONFERENCE,
            str_starts_with($dst, 'App:')   => self::KIND_IVR,
            default                         => self::KIND_PERSON,
        };
    }

    /**
     * Inbound when the call came from a provider, outbound when a leg went
     * to one. Accounts are empty on some legs (e.g. after a transfer), so the
     * channel name ("PJSIP/<endpoint>-<n>") is checked as well.
     */
    private function direction(array $legs, array $first): string
    {
        if ($this->isProvider((string)$first['from_account']) || $this->isProvider(self::endpoint((string)$first['src_chan']))) {
            return self::DIRECTION_IN;
        }
        return $this->trunkLeg($legs) !== null ? self::DIRECTION_OUT : self::DIRECTION_INTERNAL;
    }

    /** The leg that went out to a provider, if any. */
    private function trunkLeg(array $legs): ?array
    {
        foreach ($legs as $leg) {
            if ($this->isProvider((string)$leg['to_account']) || $this->isProvider(self::endpoint((string)$leg['dst_chan']))) {
                return $leg;
            }
        }
        return null;
    }

    private function providerOf(array $first, ?array $trunkLeg, string $direction): ?string
    {
        $leg = match ($direction) {
            self::DIRECTION_IN  => ['account' => $first['from_account'], 'chan' => $first['src_chan']],
            self::DIRECTION_OUT => ['account' => $trunkLeg['to_account'] ?? '', 'chan' => $trunkLeg['dst_chan'] ?? ''],
            default             => null,
        };
        if ($leg === null) {
            return null;
        }
        if ($this->isProvider((string)$leg['account'])) {
            return (string)$leg['account'];
        }
        $endpoint = self::endpoint((string)$leg['chan']);
        return $this->isProvider($endpoint) ? $endpoint : null;
    }

    /**
     * @param list<array<string, mixed>> $answered People who talked to the caller.
     */
    private function outcome(string $direction, array $legs, array $answered, ?array $trunkLeg): string
    {
        if ($direction === self::DIRECTION_OUT) {
            if ((int)($trunkLeg['billsec'] ?? 0) > 0) {
                return self::OUTCOME_ANSWERED;
            }
            return $this->unansweredStatus([$trunkLeg]) ?? self::OUTCOME_NO_ANSWER;
        }

        if ($answered !== []) {
            return self::OUTCOME_ANSWERED;
        }
        $kinds = array_column($legs, 'kind');
        if (in_array(self::KIND_VOICEMAIL, $kinds, true)) {
            return self::OUTCOME_VOICEMAIL;
        }
        $people = array_filter($legs, fn (array $l): bool => $l['kind'] === self::KIND_PERSON);
        $status = $this->unansweredStatus($people);
        if ($status !== null) {
            return $status;
        }
        if ($direction === self::DIRECTION_IN) {
            // Nobody answered: where the call went tells where the caller gave up.
            if (in_array(self::KIND_QUEUE, $kinds, true)) {
                return self::OUTCOME_ABANDONED_QUEUE;
            }
            if (in_array(self::KIND_OUT_OF_HOURS, $kinds, true)) {
                return self::OUTCOME_OUT_OF_HOURS;
            }
            if ($people === [] && in_array(self::KIND_IVR, $kinds, true)) {
                return self::OUTCOME_ABANDONED_IVR;
            }
        }
        return self::OUTCOME_NO_ANSWER;
    }

    /**
     * BUSY or FAILED when every person (or trunk) leg ended that way.
     *
     * @param array<array<string, mixed>|null> $legs
     */
    private function unansweredStatus(array $legs): ?string
    {
        $legs = array_filter($legs);
        if ($legs === []) {
            return null;
        }
        $statuses = array_unique(array_map(
            fn (array $l): string => strtoupper((string)($l['dialstatus'] ?: $l['disposition'])),
            $legs
        ));
        if ($statuses === ['BUSY']) {
            return self::OUTCOME_BUSY;
        }
        if (array_diff($statuses, self::FAILED_STATUSES) === []) {
            return self::OUTCOME_FAILED;
        }
        return null;
    }

    private function callee(string $direction, array $first, ?array $trunkLeg): string
    {
        return match ($direction) {
            self::DIRECTION_OUT => (string)($trunkLeg['dst_num'] ?? $first['dst_num']),
            self::DIRECTION_IN  => (string)($first['did'] ?: $first['dst_num']),
            default             => (string)$first['dst_num'],
        };
    }

    /** The first queue the caller reached (not one only a transfer consultation rang). */
    private function firstQueue(array $legs, array $first): ?string
    {
        foreach ($legs as $leg) {
            if ($leg['kind'] === self::KIND_QUEUE && $this->onCallerSide($leg, $first)) {
                return substr((string)$leg['dst_chan'], strlen('Queue:'));
            }
        }
        return null;
    }

    private function ivrDigits(array $legs): ?string
    {
        $digits = [];
        foreach ($legs as $leg) {
            if ($leg['kind'] === self::KIND_IVR && (string)$leg['dtmf_digits'] !== '') {
                $digits[] = (string)$leg['dtmf_digits'];
            }
        }
        return $digits === [] ? null : implode(',', $digits);
    }

    private function seconds(array $legs, string $kind): int
    {
        $total = 0.0;
        foreach ($legs as $leg) {
            if ($leg['kind'] === $kind) {
                $total += $leg['end_ts'] - $leg['start_ts'];
            }
        }
        return (int)round($total);
    }

    /** Recorded when a person's leg has a recording (IVR and voicemail recordings don't count). */
    private function recorded(array $legs): bool
    {
        foreach ($legs as $leg) {
            if ($leg['kind'] === self::KIND_PERSON && (string)$leg['recordingfile'] !== '') {
                return true;
            }
        }
        return false;
    }

    private function latestEnd(array $legs): ?string
    {
        $latest = null;
        foreach ($legs as $leg) {
            if ($leg['endtime'] !== '' && ($latest === null || $leg['end_ts'] > $latest['end_ts'])) {
                $latest = $leg;
            }
        }
        return $latest === null ? null : (string)$latest['endtime'];
    }

    private function firstNonEmpty(array $legs, string $column): ?string
    {
        foreach ($legs as $leg) {
            if ((string)$leg[$column] !== '') {
                return (string)$leg[$column];
            }
        }
        return null;
    }

    /**
     * A provider in the Providers table, or an endpoint that is named like one
     * (SIP-TRUNK-..., SIP-PROVIDER-..., SIP-..., IAX-...) even though its
     * provider has since been deleted: a removed trunk's past calls stay
     * inbound or outbound. Extensions appear in the CDR as plain numbers.
     */
    private function isProvider(string $id): bool
    {
        return $id !== '' && (isset($this->providers[$id]) || preg_match('/^(SIP|IAX)-[A-Z0-9-]+$/i', $id) === 1);
    }

    /** "PJSIP/SIP-TRUNK-9B90C86F-0000007b" -> "SIP-TRUNK-9B90C86F". */
    private static function endpoint(string $channel): string
    {
        if (!str_starts_with($channel, 'PJSIP/')) {
            return '';
        }
        $name = substr($channel, strlen('PJSIP/'));
        $dash = strrpos($name, '-');
        return $dash === false ? $name : substr($name, 0, $dash);
    }

    /**
     * The extensions an inbound or internal call rang, one row each: whether
     * that person answered, ring time and talk time. Legs are those on the
     * caller's side to a person (queue members, direct, transfer targets,
     * pickups); an attended transfer's consult leg, from the transferring
     * extension, is left out. A pickup counts for whoever picked up
     * (dst_num), not the to_account that still names the rung extension.
     *
     * @param list<array<string, mixed>> $legs
     *
     * @return list<array<string, int|string|null>> m_AgentCall rows (without id).
     */
    public function agents(array $legs): array
    {
        $row = $this->index($legs);
        if ($row['direction'] === self::DIRECTION_OUT) {
            return [];
        }
        $legs = $this->prepare($legs);
        $first = $this->firstLeg($legs);
        $byExtension = [];
        foreach ($legs as $leg) {
            $extension = (string)$leg['dst_num'];
            if ($leg['kind'] !== self::KIND_PERSON || !$this->onCallerSide($leg, $first) || !preg_match('/^\d{2,11}$/', $extension)
                || $this->isProvider((string)$leg['to_account']) || $this->isProvider(self::endpoint((string)$leg['dst_chan']))) {
                continue;
            }
            $answered = (int)$leg['billsec'] > 0;
            $ringEnd = $leg['answer_ts'] ?? $leg['end_ts'];
            $agent = $byExtension[$extension] ?? [
                'linkedid' => $row['linkedid'], 'local_date' => $row['local_date'], 'direction' => $row['direction'],
                'did' => $row['did'], 'first_queue' => $row['first_queue'], 'extension' => $extension,
                'answered' => 0, 'ring_seconds' => 0, 'talk_seconds' => 0,
            ];
            $agent['answered'] = max($agent['answered'], $answered ? 1 : 0);
            $agent['ring_seconds'] += (int)round(max(0.0, $ringEnd - $leg['start_ts']));
            $agent['talk_seconds'] += (int)$leg['billsec'];
            $byExtension[$extension] = $agent;
        }

        ksort($byExtension, SORT_STRING);

        return array_values($byExtension);
    }

    /**
     * A cheap fingerprint of the legs' content, so the nightly reconcile
     * notices legs the core updated in place (same ids, new values).
     * CdrReader::dayFingerprint() computes the same sum in SQL.
     */
    public static function checksum(array $legs): int
    {
        $sum = 0;
        foreach ($legs as $leg) {
            $sum += (int)$leg['billsec'] + 3 * (int)$leg['duration'] + ((string)$leg['answer'] !== '' ? 7 : 0);
        }
        return $sum;
    }

    /**
     * Seconds since the epoch for a CDR time: local time, with or without
     * milliseconds. Only differences are used, so the time zone is irrelevant.
     */
    private static function timestamp(string $time): float
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.v', $time)
            ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $time);
        if ($parsed === false) {
            throw new InvalidArgumentException("Unreadable CDR time: '$time'");
        }
        return (float)$parsed->format('U.v');
    }
}
