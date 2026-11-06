<?php

// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace Modules\ModuleCallInsights\Setup;

use MikoPBX\Common\Models\PbxSettings;
use MikoPBX\Modules\Setup\PbxExtensionSetupBase;

/**
 * Installer. The base class creates the tables from the model annotations
 * (installDB) and calls addToSidebar(); the worker starts the back-fill on
 * its first run, so nothing else is needed at install time.
 */
class PbxExtensionSetup extends PbxExtensionSetupBase
{
    /**
     * Puts "Call Insights" in the same sidebar group as the built-in call history.
     */
    public function addToSidebar(): bool
    {
        $key = "AdditionalMenuItem$this->moduleUniqueID";
        $menuSettings = PbxSettings::findFirstByKey($key);
        if ($menuSettings === null) {
            $menuSettings = new PbxSettings();
            $menuSettings->key = $key;
        }
        $menuSettings->value = json_encode([
            'uniqid'        => $this->moduleUniqueID,
            'group'         => 'setup',
            'iconClass'     => 'chart bar',
            'caption'       => "Breadcrumb$this->moduleUniqueID",
            'showAtSidebar' => true,
        ]);

        return $menuSettings->save();
    }
}
