<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Lib;

use PDO;
use Throwable;

/**
 * Display names from the core's configuration database, read at query time
 * so a renamed extension, queue or trunk shows its new name everywhere
 * (plan rule 8). The index itself stores numbers and IDs only.
 *
 * These names are entered by admins, but the page still renders them as
 * text only, like every other string.
 */
final class Labels
{
    /**
     * @return array{extensions: array<string, string>, providers: array<string, string>}
     */
    public static function load(string $configDatabaseFile): array
    {
        $db = CdrReader::openReadOnly($configDatabaseFile);

        // Extensions, queues, IVR menus, conference rooms...: number => name.
        $extensions = [];
        foreach ($db->query('SELECT number, callerid FROM m_Extensions')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((string)$row['callerid'] !== '') {
                $extensions[(string)$row['number']] = (string)$row['callerid'];
            }
        }

        // Providers: uniqid => description, or the host when there is none.
        $providers = [];
        foreach (['m_Sip', 'm_Iax'] as $table) {
            try {
                $rows = $db->query("SELECT uniqid, description, host FROM $table")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable) {
                continue;     // a table missing in a test or minimal database
            }
            foreach ($rows as $row) {
                $name = trim((string)$row['description']) !== '' ? (string)$row['description'] : (string)$row['host'];
                if ($name !== '') {
                    $providers[(string)$row['uniqid']] = $name;
                }
            }
        }
        $known = $db->query('SELECT uniqid FROM m_Providers')->fetchAll(PDO::FETCH_COLUMN);

        return [
            'extensions' => $extensions,
            'providers'  => array_intersect_key($providers, array_flip(array_map('strval', $known))),
        ];
    }
}
