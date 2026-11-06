<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * One row per call and extension the call rang: whether that person
 * answered, how long it rang and the talk time. Derived from the CDR with
 * the call index (CallIndexer::agents) and rebuilt with it.
 *
 * @Indexes(
 *     [name='date_extension', columns=['local_date', 'extension'], type=''],
 *     [name='linkedid', columns=['linkedid'], type='']
 * )
 */
class AgentCall extends ModulesModelsBase
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
     * 'YYYY-MM-DD' of the call (its first leg).
     *
     * @Column(type="string", nullable=false)
     */
    public $local_date;

    /**
     * 'in' or 'internal'.
     *
     * @Column(type="string", nullable=false)
     */
    public $direction;

    /**
     * @Column(type="string", nullable=true)
     */
    public $did;

    /**
     * @Column(type="string", nullable=true)
     */
    public $first_queue;

    /**
     * The extension that rang.
     *
     * @Column(type="string", nullable=false)
     */
    public $extension;

    /**
     * 1 when this person answered the caller.
     *
     * @Column(type="integer", nullable=false, default="0")
     */
    public $answered;

    /**
     * Seconds it rang (until answered, or until the ringing stopped).
     *
     * @Column(type="integer", nullable=false, default="0")
     */
    public $ring_seconds;

    /**
     * @Column(type="integer", nullable=false, default="0")
     */
    public $talk_seconds;

    public function initialize(): void
    {
        $this->setSource('m_AgentCall');
        parent::initialize();
    }
}
