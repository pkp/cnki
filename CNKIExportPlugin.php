<?php

/**
 * @file plugins/generic/cnki/CNKIExportPlugin.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CNKIExportPlugin
 *
 * @brief CNKI export plugin
 */

namespace APP\plugins\generic\cnki;

use APP\facades\Repo;
use APP\plugins\generic\cnki\jobs\CNKIDeliver;
use APP\plugins\PubObjectsExportPlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use APP\template\TemplateManager;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use PKP\context\Context;
use PKP\db\DAORegistry;
use PKP\file\FileManager;
use PKP\notification\Notification;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\scheduledTask\PKPScheduler;
use PKP\submission\GenreDAO;
use ZipArchive;

class CNKIExportPlugin extends PubObjectsExportPlugin implements HasTaskScheduler
{
    /**
     * @copydoc ImportExportPlugin::display()
     */
    public function display($args, $request): void
    {
        parent::display($args, $request);
        $templateManager = TemplateManager::getManager();
        $templateManager->assign([
            'ftpLibraryMissing' => !class_exists('\League\Flysystem\Ftp\FtpAdapter'),
        ]);

        switch (array_shift($args)) {
            case 'index':
            case '':
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->display($this->getTemplateResource('index.tpl'));
                break;
        }
    }

    /**
     * @copydoc Plugin::getName()
     */
    public function getName(): string
    {
        return 'CNKIExportPlugin';
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.importexport.cnki.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.importexport.cnki.description.short');
    }

    /**
     * @copydoc ImportExportPlugin::getPluginSettingsPrefix()
     */
    public function getPluginSettingsPrefix(): string
    {
        return 'cnki';
    }

    /**
     * @copydoc Plugin::getEncryptedSettingFields()
     */
    public function getEncryptedSettingFields(): array
    {
        return [
            'password',
        ];
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportDeploymentClassName()
     */
    public function getExportDeploymentClassName(): string
    {
        return '\APP\plugins\generic\cnki\CNKIExportDeployment';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getSettingsFormClassName()
     */
    public function getSettingsFormClassName(): string
    {
        return '\APP\plugins\generic\cnki\classes\form\CNKISettingsForm';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getDepositSuccessNotificationMessageKey()
     */
    public function getDepositSuccessNotificationMessageKey()
    {
        return 'plugins.importexport.cnki.submit.success';
    }

    /**
     * @copydoc \PKP\plugins\interfaces\HasTaskScheduler::registerSchedules()
     */
    public function registerSchedules(PKPScheduler $scheduler): void
    {
        $scheduler
            ->addSchedule(new CNKIInfoSender())
            ->daily()
            ->name(CNKIInfoSender::class)
            ->withoutOverlapping();
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportActions()
     */
    public function getExportActions($context): array
    {
        $actions = [PubObjectsExportPlugin::EXPORT_ACTION_EXPORT, PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED];
        if ($this->hasCompleteSettings($context->getId())) {
            array_unshift($actions, PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT);
        }
        return $actions;
    }

    /**
     * Whether the configured FTP account has everything required to deliver to it.
     */
    protected function hasCompleteSettings(int $contextId): bool
    {
        return $this->isFtpAccountComplete([
            'host' => $this->getSetting($contextId, 'host'),
            'username' => $this->getSetting($contextId, 'username'),
            'password' => $this->getSetting($contextId, 'password'),
        ]);
    }

    /**
     * Whether an FTP account (host/username/password) is fully filled in. The
     * account is optional -- a journal may use the plugin for Export only and
     * deliver to CNKI manually outside OJS -- but if any field is set, all must be.
     */
    public function isFtpAccountComplete(array $account): bool
    {
        return !empty($account['host']) && !empty($account['username']) && !empty($account['password']);
    }

    /**
     * Whether none of the FTP account fields are set.
     */
    public function isFtpAccountEmpty(array $account): bool
    {
        return empty($account['host']) && empty($account['username']) && empty($account['password']);
    }

    /**
     * @copydoc PubObjectsExportPlugin::executeExportAction()
     */
    public function executeExportAction($request, $objects, $filter, $tab, $objectsFileNamePart, $noValidation = null, $shouldRedirect = true): void
    {
        $context = $request->getContext();
        $path = ['plugin', $this->getName()];

        if ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT)) {
            $result = $this->depositXML($objects, $context, null);
            if ($result === true) {
                $this->_sendNotification(
                    $request->getUser(),
                    $this->getDepositSuccessNotificationMessageKey(),
                    Notification::NOTIFICATION_TYPE_SUCCESS
                );
            } else {
                foreach ((array) $result as $error) {
                    $this->_sendNotification(
                        $request->getUser(),
                        $error[0],
                        Notification::NOTIFICATION_TYPE_ERROR,
                        ($error[1] ?? null)
                    );
                }
            }
            $request->redirect(null, null, null, $path, null, $tab);
        } elseif ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_EXPORT)) {
            $fileManager = new FileManager();
            if (count($objects) === 1) {
                $object = reset($objects);
                $result = $this->buildDocument($object, $context);
                if (isset($result['error'])) {
                    $this->_sendNotification(
                        $request->getUser(),
                        $result['error'][0],
                        Notification::NOTIFICATION_TYPE_ERROR,
                        ($result['error'][1] ?? null)
                    );
                    $request->redirect(null, null, null, $path, null, $tab);
                    return;
                }
                $tmpPath = $this->createTempPath();
                file_put_contents($tmpPath, $result['content']);
                $fileManager->downloadByPath($tmpPath, 'application/xml', false, $result['filename'] . '.xml');
                $fileManager->deleteByPath($tmpPath);
            } else {
                $result = $this->createZipCollection($objects, $context);
                if (!empty($result['error'])) {
                    $this->_sendNotification(
                        $request->getUser(),
                        $result['error'][0],
                        Notification::NOTIFICATION_TYPE_ERROR,
                        ($result['error'][1] ?? null)
                    );
                    $request->redirect(null, null, null, $path, null, $tab);
                    return;
                }
                $filename = $this->buildAcronym($context) . '_export_' . date('Y-m-d-H-i-s') . '.zip';
                $fileManager->downloadByPath($result['path'], 'application/zip', false, $filename);
                $fileManager->deleteByPath($result['path']);
            }
        } else {
            parent::executeExportAction($request, $objects, $filter, $tab, $objectsFileNamePart, $noValidation, $shouldRedirect);
        }
    }

    /**
     * Dispatches a job per selected object to build and deliver its document, so
     * the FTP upload can't block the triggering request.
     *
     * @copydoc PubObjectsExportPlugin::depositXML()
     *
     * @param Submission[]|Publication[] $objects
     * @param null|mixed $filename
     *
     * @return bool|array True on success (i.e. successfully queued), or an array of error messages.
     */
    public function depositXML($objects, $context, $filename = null): bool|array
    {
        if (!$this->hasCompleteSettings($context->getId())) {
            return [['plugins.importexport.cnki.export.failure.settings']];
        }

        foreach ($objects as $object) {
            dispatch(new CNKIDeliver(
                $object->getId(),
                $object instanceof Publication,
                $context->getId()
            ));
            $this->updateStatus($object, PubObjectsExportPlugin::EXPORT_STATUS_SUBMITTED);
        }

        return true;
    }

    /**
     * Write a document to the configured FTP account.
     */
    public function deliverToEndpoint(string $content, string $filename, Context $context): void
    {
        $adapter = new FtpAdapter(FtpConnectionOptions::fromArray([
            'host' => $this->getSetting($context->getId(), 'host'),
            'port' => ((int) $this->getSetting($context->getId(), 'port')) ?: 21,
            'username' => $this->getSetting($context->getId(), 'username'),
            'password' => $this->getSetting($context->getId(), 'password'),
            'root' => $this->getSetting($context->getId(), 'path'),
        ]));
        $fs = new Filesystem($adapter);
        $fs->write($filename, $content);
    }

    /**
     * Build the uploaded JATS full-text document for delivery, delivered as-is.
     *
     * @return array{content: string, filename: string}|array{error: array}
     */
    public function buildDocument(Submission|Publication $object, Context $context): array
    {
        $publication = $object instanceof Publication ? $object : $object->getCurrentPublication();
        $submissionId = $object instanceof Publication ? $object->getData('submissionId') : $object->getId();

        $label = $submissionId . ' VoR' . $publication->getData('versionMajor');

        /** @var GenreDAO $genreDao */
        $genreDao = DAORegistry::getDAO('GenreDAO');
        $genres = [];
        foreach ($genreDao->getEnabledByContextId($context->getId())->toArray() as $genre) {
            $genres[$genre->getId()] = $genre;
        }

        // getJatsFile() ignores jatsPublicVisibility -- that only gates the reader-facing
        // public download, unrelated to whether this content is archived externally.
        $jatsFile = Repo::jats()->getJatsFile($publication->getId(), $submissionId, array_values($genres));
        if ($jatsFile->isDefaultContent || !$jatsFile->jatsContent) {
            return ['error' => ['plugins.importexport.cnki.export.failure.noUploadedJats', $label]];
        }

        return [
            'content' => $jatsFile->jatsContent,
            'filename' => $this->buildFileName($object, $context),
        ];
    }

    /**
     * Build the delivered document's filename (without extension): {acronym}_{submissionId}_VoR{versionMajor}.
     * Only the Version of Record is ever exportable here, so the version-stage code is
     * always "VoR" -- see PubObjectsExportPlugin::getExportableVersionStages().
     */
    public function buildFileName(Submission|Publication $object, Context $context): string
    {
        $publication = $object instanceof Publication ? $object : $object->getCurrentPublication();
        $submissionId = $object instanceof Submission ? $object->getId() : $object->getData('submissionId');

        return $this->buildAcronym($context) . '_' . $submissionId . '_VoR' . $publication->getData('versionMajor');
    }

    /**
     * Bundle multiple objects' documents into a single ZIP for download.
     *
     * @param Submission[]|Publication[] $objects
     *
     * @return array{path: string}|array{error: array}
     */
    protected function createZipCollection(array $objects, Context $context): array
    {
        $zipPath = $this->createTempPath();
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            @unlink($zipPath);
            return ['error' => ['plugins.importexport.cnki.export.failure.creatingCollectionFile']];
        }

        foreach ($objects as $object) {
            $document = $this->buildDocument($object, $context);
            if (isset($document['error'])) {
                $zip->close();
                @unlink($zipPath);
                return ['error' => $document['error']];
            }
            if (!$zip->addFromString($document['filename'] . '.xml', $document['content'])) {
                $zip->close();
                @unlink($zipPath);
                return ['error' => ['plugins.importexport.cnki.export.failure.creatingCollectionFile']];
            }
        }
        $zip->close();

        return ['path' => $zipPath];
    }

    /**
     * Create an empty temp file, under files_dir/temp/.
     */
    protected function createTempPath(): string
    {
        $exportPath = $this->getExportPath();
        (new FileManager())->mkdirtree($exportPath);
        return tempnam($exportPath, 'CNKIExport_');
    }

    /**
     * Build the journal acronym component used for the outer export ZIP's filename.
     */
    protected function buildAcronym(Context $context): string
    {
        $locale = $context->getData('primaryLocale');
        $acronym = $context->getData('acronym', $locale) ?: $context->getPath();
        return preg_replace('/[^a-zA-Z0-9]/', '', $acronym);
    }

    /**
     * Helper to convert an error array to a translated string.
     */
    public function convertErrorMessage(array $errorMessage): string
    {
        return __($errorMessage[0], ['param' => $errorMessage[1] ?? null]);
    }

    /**
     * @copydoc ImportExportPlugin::executeCLI()
     */
    public function executeCLI($scriptName, &$args)
    {
    }

    /**
     * @copydoc ImportExportPlugin::usage()
     */
    public function usage($scriptName)
    {
    }
}
