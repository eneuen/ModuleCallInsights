<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Lib;

use MikoPBX\Core\System\SystemMessages;
use MikoPBX\Core\Workers\WorkerBase;
use MikoPBX\Modules\PbxExtensionUtils;
use Throwable;

require_once 'Globals.php';

/**
 * Keeps the call index current. The supervisor starts it as its own PHP
 * process (CHECK_BY_PID_NOT_ALERT) and restarts it if it exits.
 *
 * Every 60 seconds it indexes new calls (forward sync), then spends the
 * rest of its budget on the back-fill of older history. Pacing depends on
 * the storage (SyncPacing): reads are short and batched, with pauses in
 * between, and smaller still when the CDR runs without WAL.
 *
 * The sync logic itself (CallIndexSync, CdrReader, IndexStore, CallIndexer)
 * is plain PHP and unit-tested; this class only wires it to the PBX.
 */
class WorkerCallIndex extends WorkerBase
{
    public const string MODULE_ID = 'ModuleCallInsights';

    private const int CYCLE_SECONDS = 60;

    public function start(array $argv): void
    {
        $config = $this->di->getShared('config');
        $cdrFile = (string)$config->path('cdrDatabase.dbfile');
        $configFile = (string)$config->path('database.dbfile');
        $moduleFile = PbxExtensionUtils::getModuleDir(self::MODULE_ID) . '/db/module.db';

        // WAL for the module database only on a WAL-safe filesystem (plan rule 11).
        $walSafe = StorageInfo::isWalSafeFilesystem($moduleFile);
        $store = new IndexStore($moduleFile, $walSafe);
        $cdr = new CdrReader($cdrFile);
        SystemMessages::sysLogMsg(static::class, sprintf(
            'Started: module database %s, CDR journal mode %s',
            $walSafe ? 'uses WAL' : 'on network storage, WAL off',
            $cdr->journalMode()
        ), LOG_INFO);

        while ($this->needRestart === false) {
            $cycleStart = time();
            try {
                $this->runCycle($cdr, $store, $configFile);
            } catch (Throwable $e) {
                SystemMessages::sysLogMsg(static::class, 'Cycle failed: ' . $e->getMessage(), LOG_ERR);
            }
            // Sleep in one-second steps so a restart request is honoured quickly.
            while ($this->needRestart === false && time() - $cycleStart < self::CYCLE_SECONDS) {
                sleep(1);
            }
        }
    }

    private function runCycle(CdrReader $cdr, IndexStore $store, string $configFile): void
    {
        $pacing = SyncPacing::for($cdr->journalMode(), $store->backfillSpeed());
        // Re-read providers each cycle: a newly added trunk must count as external.
        $indexer = new CallIndexer(CdrReader::providerIds($configFile));
        $sync = new CallIndexSync($cdr, $store, $indexer, $pacing->batchSize);

        $forward = 0;
        for ($i = 0; $i < $pacing->maxForwardBatches && $this->needRestart === false; $i++) {
            $read = $sync->syncForward();
            $forward += $read;
            if ($read < $pacing->batchSize) {
                break;      // caught up
            }
            usleep($pacing->pauseMs * 1000);
        }

        $backfill = 0;
        if (!$sync->backfillDone()) {
            $deadline = microtime(true) + $pacing->backfillSeconds;
            while (microtime(true) < $deadline && $this->needRestart === false) {
                $read = $sync->backfillStep();
                $backfill += $read;
                if ($read === 0) {
                    SystemMessages::sysLogMsg(static::class, 'Back-fill complete', LOG_INFO);
                    break;
                }
                usleep($pacing->pauseMs * 1000);
            }
        }

        $store->setState(IndexStore::LAST_RUN_AT, date('Y-m-d H:i:s'));
        if ($forward > 0 || $backfill > 0) {
            SystemMessages::sysLogMsg(
                static::class,
                "Indexed: $forward new legs, $backfill back-filled legs, {$store->callCount()} calls in the index",
                LOG_DEBUG
            );
        }
    }
}

WorkerCallIndex::startWorker($argv ?? []);
