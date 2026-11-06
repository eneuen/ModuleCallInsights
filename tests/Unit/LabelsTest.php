<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Tests\Unit;

use Modules\ModuleCallInsights\Lib\Labels;
use Modules\ModuleCallInsights\Tests\Unit\Support\TestDatabases;
use PHPUnit\Framework\TestCase;

final class LabelsTest extends TestCase
{
    public function testReadsExtensionAndProviderNames(): void
    {
        $db = new TestDatabases();
        $file = $db->configDatabase(['SIP-TRUNK-A', 'SIP-TRUNK-B']);
        $pdo = TestDatabases::writable($file);
        $pdo->exec('CREATE TABLE m_Extensions (id INTEGER PRIMARY KEY, number VARCHAR, type VARCHAR, callerid VARCHAR)');
        $pdo->exec("INSERT INTO m_Extensions (number, type, callerid) VALUES
            ('201', 'SIP', 'Anna Smith'), ('3100', 'QUEUE', 'Sales queue'), ('202', 'SIP', '')");
        $pdo->exec('CREATE TABLE m_Sip (id INTEGER PRIMARY KEY, uniqid VARCHAR, description VARCHAR, host VARCHAR)');
        $pdo->exec("INSERT INTO m_Sip (uniqid, description, host) VALUES
            ('SIP-TRUNK-A', 'Main carrier', 'sip.example.net'), ('SIP-TRUNK-B', '', '203.0.113.5'),
            ('SIP-PHONE-X', 'Anna Smith', '')");

        $labels = Labels::load($file);

        self::assertSame(['201' => 'Anna Smith', '3100' => 'Sales queue'], $labels['extensions'], 'empty names are left out');
        self::assertSame(['SIP-TRUNK-A' => 'Main carrier', 'SIP-TRUNK-B' => '203.0.113.5'], $labels['providers'],
            'only providers, with the host when there is no description');
    }
}
