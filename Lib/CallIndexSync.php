<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Lib;


/**
 * Keeps the call index in step with the CDR, one short batch at a time.
 *
 * Forward sync (plan rule 3): read legs above the watermark, then re-index
 * every call they belong to from all of that call's legs. Legs of one call
 * arrive minutes apart, so a call is simply recomputed whenever a new leg
 * appears; running a batch twice gives the same result.
 *
 * Back-fill: on the first run the watermark is set to the newest leg, and a
 * second cursor walks backwards from there to the oldest leg, so recent
 * history is usable first and new calls keep flowing in meanwhile.
 *
 * cdr_general.id is AUTOINCREMENT (confirmed on MikoPBX 2026.6.34), so ids
 * are never reused and the watermark cannot skip a leg. Rows the core
 * updates or deletes later are caught by the nightly reconcile (plan rule 7).
 *
 * Every batch (index rows, daily totals and sync state) is saved in one
 * transaction on the module database.
 */
final class CallIndexSync
{
    public const int DEFAULT_BATCH = 500;

    public function __construct(
        private readonly CdrReader $cdr,
        private readonly IndexStore $store,
        private readonly CallIndexer $indexer,
        private readonly int $batchSize = self::DEFAULT_BATCH,
    ) {
    }

    /**
     * Sets the starting points on the very first run: the watermark at the
     * newest leg, the back-fill cursor at the same leg.
     */
    public function initialise(): void
    {
        if ($this->store->state(IndexStore::FORWARD_WATERMARK) !== null) {
            return;
        }
        $newest = $this->cdr->maxId();
        $this->store->transaction(function () use ($newest): void {
            $this->store->setState(IndexStore::FORWARD_WATERMARK, (string)$newest);
            $this->store->setState(IndexStore::BACKFILL_CURSOR, (string)$newest);
        });
    }

    /**
     * Indexes one batch of new legs.
     *
     * @return int Legs read; 0 when the index is up to date.
     */
    public function syncForward(): int
    {
        $this->initialise();
        $watermark = (int)$this->store->state(IndexStore::FORWARD_WATERMARK);
        $legs = $this->cdr->legsAfter($watermark, $this->batchSize);
        if ($legs === []) {
            return 0;
        }
        $newWatermark = max(array_map(fn (array $l): int => (int)$l['id'], $legs));
        $this->indexCalls($legs, IndexStore::FORWARD_WATERMARK, (string)$newWatermark);

        return count($legs);
    }

    /**
     * Indexes one batch of older legs, walking backwards.
     *
     * @return int Legs read; 0 when the back-fill has reached the oldest leg.
     */
    public function backfillStep(): int
    {
        $this->initialise();
        $cursor = (int)$this->store->state(IndexStore::BACKFILL_CURSOR);
        if ($cursor <= 0) {
            return 0;
        }
        $legs = $this->cdr->legsUpTo($cursor, $this->batchSize);
        if ($legs === []) {
            $this->store->setState(IndexStore::BACKFILL_CURSOR, '0');
            return 0;
        }
        $nextCursor = min(array_map(fn (array $l): int => (int)$l['id'], $legs)) - 1;
        $this->indexCalls($legs, IndexStore::BACKFILL_CURSOR, (string)max(0, $nextCursor));

        return count($legs);
    }

    public function backfillDone(): bool
    {
        $cursor = $this->store->state(IndexStore::BACKFILL_CURSOR);

        return $cursor !== null && (int)$cursor <= 0;
    }

    /**
     * Re-indexes every call the given legs belong to, then moves the cursor.
     *
     * @param list<array<string, mixed>> $legs
     */
    private function indexCalls(array $legs, string $cursorName, string $cursorValue): void
    {
        $linkedIds = array_values(array_unique(array_column($legs, 'linkedid')));
        $byCall = [];
        foreach ($this->cdr->legsOfCalls($linkedIds) as $leg) {
            $byCall[(string)$leg['linkedid']][] = $leg;
        }
        $rows = array_map(fn (array $callLegs): array => $this->indexer->index($callLegs), array_values($byCall));
        $agents = array_map(fn (array $callLegs): array => $this->indexer->agents($callLegs), $byCall);

        $this->store->transaction(function () use ($rows, $agents, $cursorName, $cursorValue): void {
            $dates = $this->store->upsertCalls($rows);
            $this->store->replaceAgents($agents);
            $this->store->rebuildDailyTotals($dates);
            $this->store->setState($cursorName, $cursorValue);
        });
    }
}
