<?php

/**
 * @file plugins/generic/cnki/classes/form/CNKISettingsForm.php
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class CNKISettingsForm
 *
 * @brief Form for journal managers to configure CNKI delivery
 */

namespace APP\plugins\generic\cnki\classes\form;

use APP\plugins\generic\cnki\CNKIExportPlugin;
use APP\plugins\PubObjectsExportSettingsForm;
use PKP\form\validation\FormValidator;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class CNKISettingsForm extends PubObjectsExportSettingsForm
{
    /**
     * Constructor
     */
    public function __construct(private readonly CNKIExportPlugin $plugin, private readonly int $contextId)
    {
        parent::__construct($this->plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        // The FTP account is optional (Export-only use is valid), but partially
        // filling it in is not -- either all of host/username/password, or none.
        $this->addCheck(
            new FormValidatorCustom(
                $this,
                'host',
                FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
                'plugins.importexport.cnki.settings.form.accountIncomplete',
                fn () => $this->plugin->isFtpAccountEmpty($this->getFtpAccountData()) || $this->plugin->isFtpAccountComplete($this->getFtpAccountData())
            )
        );
        $this->addCheck(
            new FormValidatorCustom(
                $this,
                'automaticRegistration',
                FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
                'plugins.importexport.cnki.settings.form.automaticRegistrationRequiresAccount',
                fn () => $this->plugin->isFtpAccountComplete($this->getFtpAccountData())
            )
        );
    }

    /**
     * The submitted (not yet saved) host/username/password.
     */
    protected function getFtpAccountData(): array
    {
        return [
            'host' => $this->getData('host'),
            'username' => $this->getData('username'),
            'password' => $this->getData('password'),
        ];
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
    {
        foreach ($this->getFormFields() as $fieldName => $fieldType) {
            $this->setData($fieldName, $this->plugin->getSetting($this->contextId, $fieldName));
        }
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData(): void
    {
        $this->readUserVars(array_keys($this->getFormFields()));
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs): void
    {
        parent::execute(...$functionArgs);
        foreach ($this->getFormFields() as $fieldName => $fieldType) {
            $this->plugin->updateSetting($this->contextId, $fieldName, $this->getData($fieldName), $fieldType);
        }
    }

    /**
     * @copydoc PubObjectsExportSettingsForm::getFormFields()
     */
    public function getFormFields(): array
    {
        return [
            'host' => 'string',
            'port' => 'string',
            'path' => 'string',
            'username' => 'string',
            'password' => 'string',
            'automaticRegistration' => 'bool',
        ];
    }

    /**
     * @copydoc PubObjectsExportSettingsForm::isOptional()
     */
    public function isOptional(string $settingName): bool
    {
        return in_array($settingName, ['host', 'port', 'path', 'username', 'password', 'automaticRegistration']);
    }
}
