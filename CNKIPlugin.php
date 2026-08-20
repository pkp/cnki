<?php

/**
 * @file plugins/generic/cnki/CNKIPlugin.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CNKIPlugin
 *
 * @brief Plugin to export and deliver articles to CNKI.
 */

namespace APP\plugins\generic\cnki;

use APP\plugins\PubObjectsExportGenericPlugin;
use PKP\plugins\PluginRegistry;

class CNKIPlugin extends PubObjectsExportGenericPlugin
{
    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        return parent::register($category, $path, $mainContextId);
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.cnki.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.cnki.description');
    }

    protected function setExportPlugin(): void
    {
        PluginRegistry::register('importexport', new CNKIExportPlugin(), $this->getPluginPath());
        $this->exportPlugin = PluginRegistry::getPlugin('importexport', 'CNKIExportPlugin');
    }

    /**
     * @copydoc Plugin::getContextSpecificPluginSettingsFile()
     */
    public function getContextSpecificPluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * @copydoc Plugin::getInstallSitePluginSettingsFile()
     */
    public function getInstallSitePluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }
}
