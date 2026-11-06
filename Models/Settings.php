<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Models;

use MikoPBX\Modules\Models\ModulesModelsBase;

/**
 * Module settings: a single row. Everything else follows the PBX's own settings.
 */
class Settings extends ModulesModelsBase
{
    /**
     * @Primary
     * @Identity
     * @Column(type="integer", nullable=false)
     */
    public $id;

    /**
     * Service-level threshold in seconds.
     *
     * @Column(type="integer", nullable=false, default="20")
     */
    public $service_level_seconds;

    /**
     * Callback window in hours.
     *
     * @Column(type="integer", nullable=false, default="24")
     */
    public $callback_window_hours;

    /**
     * 'slow', 'normal' or 'fast'.
     *
     * @Column(type="string", nullable=false, default="normal")
     */
    public $backfill_speed;

    public function initialize(): void
    {
        $this->setSource('m_Settings');
        parent::initialize();
    }
}
