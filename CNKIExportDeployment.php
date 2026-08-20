<?php

/**
 * @file plugins/generic/cnki/CNKIExportDeployment.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CNKIExportDeployment
 *
 * @brief Unused stub required by PubObjectsExportPlugin::getExportDeploymentClassName();
 *  this plugin delivers the uploaded JATS document directly (see CNKIExportPlugin::depositXML())
 *  rather than through the filter-based XML export/deployment machinery.
 */

namespace APP\plugins\generic\cnki;

use PKP\context\Context;

class CNKIExportDeployment
{
    public function __construct(public Context $context, public CNKIExportPlugin $plugin)
    {
    }
}
