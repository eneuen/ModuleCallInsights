<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * One row per call (per linkedid), derived from the CDR and rebuildable at
 * any time. Holds numbers and IDs only; names are joined at query time.
 *
 * Times are PBX local time, copied as the CDR stores them (no offset).
 *
 * @Indexes(
 *     [name='linkedid', columns=['linkedid'], type='UNIQUE'],
 *     [name='local_date', columns=['local_date'], type=''],
 *     [name='date_direction', columns=['local_date', 'direction'], type=''],
 *     [name='did', columns=['did'], type=''],
 *     [name='answered_by', columns=['answered_by'], type=''],
 *     [name='caller_num', columns=['caller_num'], type='']
 * )
 */
class CallIndex extends ModulesModelsBase
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
    public $linkedid;

    /**
     * Call start, 'YYYY-MM-DD HH:MM:SS.mmm' local time.
     *
     * @Column(type="string", nullable=false)
     */
    public $start_time;

    /**
     * End of the last leg.
     *
     * @Column(type="string", nullable=true)
     */
    public $end_time;

    /**
     * 'YYYY-MM-DD'.
     *
     * @Column(type="string", nullable=false)
     */
    public $local_date;

    /**
     * 0-23.
     *
     * @Column(type="integer", nullable=false)
     */
    public $local_hour;

    /**
     * ISO weekday, 1 = Monday ... 7 = Sunday.
     *
     * @Column(type="integer", nullable=false)
     */
    public $weekday;

    /**
     * 'in', 'out' or 'internal'.
     *
     * @Column(type="string", nullable=false)
     */
    public $direction;

    /**
     * @Column(type="string", nullable=true)
     */
    public $did;

    /**
     * Provider ID from the Providers table.
     *
     * @Column(type="string", nullable=true)
     */
    public $provider_id;

    /**
     * Queue extension of the first queue leg.
     *
     * @Column(type="string", nullable=true)
     */
    public $first_queue;

    /**
     * @Column(type="string", nullable=true)
     */
    public $ivr_digits;

    /**
     * Extension of the person who answered.
     *
     * @Column(type="string", nullable=true)
     */
    public $answered_by;

    /**
     * Outcome code, e.g. 'answered', 'abandoned', 'voicemail', 'no_answer'.
     *
     * @Column(type="string", nullable=false)
     */
    public $outcome;

    /**
     * @Column(type="integer", nullable=false, default="0")
     */
    public $ivr_seconds;

    /**
     * Start to first person's answer; null when unanswered.
     *
     * @Column(type="integer", nullable=true)
     */
    public $wait_seconds;

    /**
     * @Column(type="integer", nullable=false, default="0")
     */
    public $talk_seconds;

    /**
     * @Column(type="integer", nullable=false, default="0")
     */
    public $total_seconds;

    /**
     * @Column(type="integer", nullable=false, default="0")
     */
    public $leg_count;

    /**
     * @Column(type="integer", nullable=false, default="0")
     */
    public $transfer_count;

    /**
     * @Column(type="integer", nullable=false, default="0")
     */
    public $recorded;

    /**
     * @Column(type="string", nullable=true)
     */
    public $caller_num;

    /**
     * @Column(type="string", nullable=true)
     */
    public $callee_num;

    /**
     * Highest cdr_general.id among the call's legs (reconcile fingerprint).
     *
     * @Column(type="integer", nullable=false, default="0")
     */
    public $max_leg_id;

    /**
     * Fingerprint of the legs' content (CallIndexer::checksum), for the reconcile.
     *
     * @Column(type="integer", nullable=false, default="0")
     */
    public $legs_checksum;

    /**
     * Version of the metric logic that built this row.
     *
     * @Column(type="integer", nullable=false, default="1")
     */
    public $logic_version;

    public function initialize(): void
    {
        $this->setSource('m_CallIndex');
        parent::initialize();
    }
}
