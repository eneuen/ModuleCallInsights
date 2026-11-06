<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Lib;

use DateTimeImmutable;

/**
 * Keeps the call index and the daily totals trustworthy over time
 * (plan rules 1, 5, 6 and 7).
 *
 * - Rebuild: when rows were built by older metric logic, or the admin
 *   asked for it, the index is emptied and the worker indexes the CDR again.
 *   Daily totals are kept; frozen days are never touched.
 * - Freeze: the core deletes CDR days older than its retention period
 *   (PBXRecordSavePeriod; under 30 means unlimited). A day's totals are
 *   frozen FREEZE_MARGIN_DAYS before the cleanup reaches it, so a
 *   half-deleted day can never overwrite them. Cheap; runs every cycle, so
 *   shortening the retention period is caught quickly.
 * - Purge: index rows of days the CDR no longer holds are removed, so phone
 *   numbers are never kept longer than MikoPBX keeps them.
 * - Reconcile: for every day not yet frozen, the CDR's fingerprint (calls,
 *   legs, highest leg id, content checksum) is compared with the index, and
 *   a day that differs is indexed again. This catches legs the core updated
 *   in place or deleted, which the forward sync cannot see.
 *
 * Plain PHP, tested without a PBX.
 */
final class IndexMaintenance
{
    public const int FREEZE_MARGIN_DAYS = 14;
    /** Retention below this many days means "unlimited" (the core's rule). */
    public const int UNLIMITED_BELOW_DAYS = 30;

    public function __construct(
        private readonly CdrReader $cdr,
        private readonly IndexStore $store,
        private readonly CallIndexer $indexer,
        private readonly int $retentionDays,
        private readonly DateTimeImmutable $today,
    ) {
    }

    /**
     * Empties the index when it was built by older logic or a rebuild was
     * requested. The worker's back-fill then indexes everything again.
     */
    public function rebuildIfNeeded(): bool
    {
        if ($this->store->state(IndexStore::REBUILD_REQUESTED) === '1'
            || $this->store->hasRowsOlderThan(CallIndexer::LOGIC_VERSION)) {
            $this->store->resetIndex();
            return true;
        }
        return false;
    }

    /** First day whose totals may still change; null when retention is unlimited. */
    public function freezeBefore(): ?string
    {
        if ($this->retentionDays < self::UNLIMITED_BELOW_DAYS) {
            return null;
        }
        $days = $this->retentionDays - self::FREEZE_MARGIN_DAYS;

        return $this->today->modify("-$days days")->format('Y-m-d');
    }

    /** @return int Daily-total rows frozen. */
    public function freeze(): int
    {
        $before = $this->freezeBefore();

        return $before === null ? 0 : $this->store->freezeTotalsBefore($before);
    }

    /**
     * Removes index rows of days older than the oldest day still in the CDR
     * (all rows when the CDR is empty).
     *
     * @return int Calls removed.
     */
    public function purge(): int
    {
        $oldest = $this->cdr->oldestDay();

        return $this->store->deleteCallsBefore($oldest ?? '9999-12-31');
    }

    /**
     * Compares each unfrozen day up to yesterday with the CDR and re-indexes
     * the days that differ. Today is left to the forward sync.
     *
     * @param callable(): void|null $pause Called between days, to spread the reads out.
     *
     * @return list<string> The days that were re-indexed.
     */
    public function reconcile(?callable $pause = null): array
    {
        [$oldestIndexed] = $this->store->indexedRange();
        $oldestCdr = $this->cdr->oldestDay();
        if ($oldestCdr === null) {
            return [];
        }
        $from = max(array_filter([$oldestIndexed ?? $oldestCdr, $oldestCdr, $this->freezeBefore()]));
        $yesterday = $this->today->modify('-1 day')->format('Y-m-d');

        $fixed = [];
        for ($day = new DateTimeImmutable($from); $day->format('Y-m-d') <= $yesterday; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            if ($this->store->isDayFrozen($date)) {
                continue;
            }
            if ($this->cdr->dayFingerprint($date) !== $this->store->dayFingerprint($date)) {
                $this->reindexDay($date);
                $fixed[] = $date;
            }
            if ($pause !== null) {
                $pause();
            }
        }

        return $fixed;
    }

    /** Re-indexes one day from the CDR: removes vanished calls, recomputes the rest. */
    public function reindexDay(string $date): void
    {
        $linkedIds = $this->cdr->linkedIdsOfDay($date);
        $byCall = [];
        foreach ($this->cdr->legsOfCalls($linkedIds) as $leg) {
            $byCall[(string)$leg['linkedid']][] = $leg;
        }
        $rows = array_map(fn (array $legs): array => $this->indexer->index($legs), array_values($byCall));

        $this->store->transaction(function () use ($date, $linkedIds, $rows): void {
            $this->store->deleteCallsOfDayExcept($date, $linkedIds);
            $this->store->upsertCalls($rows);
            $this->store->rebuildDailyTotals([$date]);
        });
    }
}
