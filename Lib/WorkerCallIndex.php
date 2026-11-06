<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Lib;

use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\Workers\WorkerBase;
use MikoPBX\Modules\Models\ModulesModelsBase;
use MikoPBX\Modules\PbxExtensionUtils;
use Modules\ModuleCallInsights\Models\SyncState;
use Throwable;

require_once 'Globals.php';

/**
 * Keeps the call index current. The supervisor starts it as its own PHP
 * process (CHECK_BY_PID_NOT_ALERT) and restarts it if it exits.
 *
 * Each cycle will run the incremental sync and the back-fill once the call
 * indexer exists (next phase-1 step); for now it prepares the module
 * database and records that it is alive.
 */
class WorkerCallIndex extends WorkerBase
{
    public const string MODULE_ID = 'ModuleCallInsights';

    private const int CYCLE_SECONDS = 60;

    /** Milliseconds a module DB statement waits for a lock before failing. */
    private const int BUSY_TIMEOUT_MS = 5000;

    public function start(array $argv): void
    {
        $this->configureModuleDatabase();

        while ($this->needRestart === false) {
            try {
                $this->recordRun();
            } catch (Throwable $e) {
                SystemMessages::sysLogMsg(static::class, 'Cycle failed: ' . $e->getMessage(), LOG_ERR);
            }

            // Sleep in one-second steps so a restart request is honoured quickly.
            for ($i = 0; $i < self::CYCLE_SECONDS && $this->needRestart === false; $i++) {
                sleep(1);
            }
        }
    }

    /**
     * WAL only on a WAL-safe filesystem (plan rule 11). The core sets no
     * journal mode for module databases, and WAL on NFS/SMB can corrupt them.
     */
    private function configureModuleDatabase(): void
    {
        $dbFile = PbxExtensionUtils::getModuleDir(self::MODULE_ID) . '/db/module.db';
        $walSafe = StorageInfo::isWalSafeFilesystem($dbFile);

        $connection = $this->di->getShared(ModulesModelsBase::getConnectionServiceName(self::MODULE_ID));
        $connection->execute('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        $connection->execute($walSafe ? 'PRAGMA journal_mode = WAL' : 'PRAGMA journal_mode = DELETE');

        SystemMessages::sysLogMsg(
            static::class,
            $walSafe ? 'Module database uses WAL' : 'Module database on network storage: WAL off',
            LOG_INFO
        );
    }

    private function recordRun(): void
    {
        $state = SyncState::findFirst([
            'conditions' => 'name = :name:',
            'bind'       => ['name' => SyncState::LAST_RUN_AT],
        ]);
        if ($state === null) {
            $state = new SyncState();
            $state->name = SyncState::LAST_RUN_AT;
        }
        $state->value = date('Y-m-d H:i:s');
        $state->save();
    }
}

WorkerCallIndex::startWorker($argv ?? []);
