<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * A named Explore report, shared by all admins. The definition holds only
 * the report's choices (metric, breakdown, range, filters), checked by
 * Lib/SavedReports before it is stored; never call data.
 *
 * @Indexes(
 *     [name='name', columns=['name'], type='UNIQUE']
 * )
 */
class SavedReport extends ModulesModelsBase
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
    public $name;

    /**
     * The checked definition, as JSON.
     *
     * @Column(type="string", nullable=false)
     */
    public $definition;

    /**
     * @Column(type="string", nullable=false)
     */
    public $updated_at;

    public function initialize(): void
    {
        $this->setSource('m_SavedReport');
        parent::initialize();
    }
}
