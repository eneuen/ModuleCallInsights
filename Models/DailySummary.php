<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * Daily totals: the only record of a day once the CDR has deleted it.
 * Counts and sums only, and no caller numbers. Survives the Rebuild button.
 *
 * Schema changes here must keep existing rows: only add columns, never
 * change or drop one (the core copies the table when a column changes).
 *
 * @Indexes(
 *     [name='dims', columns=['local_date', 'direction', 'did', 'provider_id', 'queue', 'answered_by', 'outcome'], type='UNIQUE'],
 *     [name='local_date', columns=['local_date'], type='']
 * )
 */
class DailySummary extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * @Column(type="string", nullable=false)
     */
    public $local_date;

    /**
     * @Column(type="string", nullable=false)
     */
    public $direction;

    /**
     * Empty string when none, so the unique index works.
     *
     * @Column(type="string", nullable=false, default="")
     */
    public $did;

    /**
     * @Column(type="string", nullable=false, default="")
     */
    public $provider_id;

    /**
     * @Column(type="string", nullable=false, default="")
     */
    public $queue;

    /**
     * @Column(type="string", nullable=false, default="")
     */
    public $answered_by;

    /**
     * @Column(type="string", nullable=false)
     */
    public $outcome;

    /**
     * @Column(type="integer", nullable=false, default="0")
     */
    public $calls;

    /**
     * @Column(type="integer", nullable=false, default="0")
     */
    public $answered;

    /**
     * @Column(type="integer", nullable=false, default="0")
     */
    public $talk_seconds;

    /**
     * Sum of time to answer over answered calls.
     *
     * @Column(type="integer", nullable=false, default="0")
     */
    public $wait_seconds;

    /**
     * Answered within 0-10 s.
     *
     * @Column(type="integer", nullable=false, default="0")
     */
    public $tta_0_10;

    /**
     * @Column(type="integer", nullable=false, default="0")
     */
    public $tta_10_20;

    /**
     * @Column(type="integer", nullable=false, default="0")
     */
    public $tta_20_30;

    /**
     * @Column(type="integer", nullable=false, default="0")
     */
    public $tta_30_60;

    /**
     * @Column(type="integer", nullable=false, default="0")
     */
    public $tta_60_120;

    /**
     * @Column(type="integer", nullable=false, default="0")
     */
    public $tta_120_plus;

    /**
     * 1 once the day is frozen and must never be recomputed.
     *
     * @Column(type="integer", nullable=false, default="0")
     */
    public $frozen;

    public function initialize(): void
    {
        $this->setSource('m_DailySummary');
        parent::initialize();
    }
}
