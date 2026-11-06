<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Lib;

use MikoPBX\Core\Workers\Cron\WorkerSafeScriptsCore;
use MikoPBX\Modules\Config\ConfigClass;

/**
 * Module hooks. The core finds this class by naming convention:
 * Modules\<UniqueID>\Lib\<UniqueID without "Module">Conf.
 *
 * Deliberately not implemented: getPBXCoreRESTAdditionalRoutes() and
 * moduleRestAPICallback(). The module serves data only through its
 * admin-cabinet controller (see the plan's Security section).
 */
class CallInsightsConf extends ConfigClass
{
    /**
     * The indexer worker. The supervisor restarts it if its process is gone.
     */
    public function getModuleWorkers(): array
    {
        return [
            [
                'type'   => WorkerSafeScriptsCore::CHECK_BY_PID_NOT_ALERT,
                'worker' => WorkerCallIndex::class,
            ],
        ];
    }
}
