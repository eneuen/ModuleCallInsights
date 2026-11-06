<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;
use Modules\ModuleCallInsights\Lib\IndexStore;

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
    // The names live in IndexStore, which the pure-PHP sync code (and its
    // tests) can load without the core.
    public const string FORWARD_WATERMARK = IndexStore::FORWARD_WATERMARK;
    public const string BACKFILL_CURSOR = IndexStore::BACKFILL_CURSOR;
    public const string REBUILD_REQUESTED = IndexStore::REBUILD_REQUESTED;
    public const string LAST_RUN_AT = IndexStore::LAST_RUN_AT;

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
