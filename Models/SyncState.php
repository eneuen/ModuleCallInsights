<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * Indexer bookkeeping as name/value pairs: the forward watermark, the
 * back-fill cursor, the rebuild flag, the last run time.
 *
 * @Indexes(
 *     [name='name', columns=['name'], type='UNIQUE']
 * )
 */
class SyncState extends ModulesModelsBase
{
    public const string FORWARD_WATERMARK = 'forward_watermark';
    public const string BACKFILL_CURSOR = 'backfill_cursor';
    public const string REBUILD_REQUESTED = 'rebuild_requested';
    public const string LAST_RUN_AT = 'last_run_at';

    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * @Column(type="string", nullable=false)
     */
    public $name;

    /**
     * @Column(type="string", nullable=true)
     */
    public $value;

    public function initialize(): void
    {
        $this->setSource('m_SyncState');
        parent::initialize();
    }
}
