<?php

/**
 * @file plugins/generic/cnki/jobs/CNKIDeliver.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CNKIDeliver
 *
 * @ingroup jobs
 *
 * @brief Build a document and deliver it to the configured CNKI FTP account.
 */

namespace APP\plugins\generic\cnki\jobs;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\cnki\CNKIExportPlugin;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use PKP\job\exceptions\JobException;
use PKP\jobs\BaseJob;
use PKP\plugins\PluginRegistry;
use Throwable;

class CNKIDeliver extends BaseJob
{
    public function __construct(
        protected int $objectId,
        protected bool $isPublication,
        protected int $contextId
    ) {
        parent::__construct();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        /** @var Submission|Publication|null $object */
        $object = $this->isPublication
            ? Repo::publication()->get($this->objectId)
            : Repo::submission()->get($this->objectId);

        if (!$object) {
            throw new JobException(JobException::INVALID_PAYLOAD);
        }

        PluginRegistry::register('importexport', new CNKIExportPlugin(), 'plugins/generic/cnki/CNKIExportPlugin', $this->contextId);
        /** @var CNKIExportPlugin $plugin */
        $plugin = PluginRegistry::getPlugin('importexport', 'CNKIExportPlugin');

        if ($object->getData($plugin->getDepositStatusSettingName()) === PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED) {
            return;
        }

        $context = Application::getContextDAO()->getById($this->contextId);
        $document = $plugin->buildDocument($object, $context);
        if (isset($document['error'])) {
            $errorMessage = $plugin->convertErrorMessage($document['error']);
            $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $errorMessage);
            throw new JobException($errorMessage);
        }

        try {
            $plugin->deliverToEndpoint($document['content'], $document['filename'] . '.xml', $context);
            $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_REGISTERED);
        } catch (Throwable $e) {
            $plugin->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_ERROR, $e->getMessage());
            throw new JobException($e->getMessage());
        }
    }
}
