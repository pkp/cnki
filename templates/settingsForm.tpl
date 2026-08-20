{**
 * templates/settingsForm.tpl
 *
 * Copyright (c) 2026 Simon Fraser University
 * Copyright (c) 2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * CNKI plugin settings.
 *
 *}
<script type="text/javascript">
	$(function() {ldelim}
		// Attach the form handler.
		$('#cnkiSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim})
</script>
<div class="legacyDefaults">
	<form class="pkp_form" method="post" id="cnkiSettingsForm" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" plugin="CNKIExportPlugin" category="importexport" verb="save"}">
		{csrf}
		{include file="controllers/notification/inPlaceNotification.tpl" notificationId="cnkiSettingsFormNotification"}
		{fbvFormArea id="cnkiSettingsFormArea"}
			<p class="pkp_help">
				{translate key="plugins.importexport.cnki.description"}
			</p>
			<br/>
			{fbvFormSection list="true"}
				{fbvElement type="checkbox" id="automaticRegistration" label="plugins.importexport.cnki.settings.form.automaticRegistration.description" checked=$automaticRegistration|compare:true}
			{/fbvFormSection}

			{capture assign="sectionTitle"}{translate key="plugins.importexport.cnki.endpoint"}{/capture}
			{fbvFormSection id="formSection" title=$sectionTitle translate=false class="endpointContainer"}
				{fbvElement type="text" id="host" value=$host label="plugins.importexport.cnki.host" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="port" value=$port label="plugins.importexport.cnki.port" maxlength="5" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="path" value=$path label="plugins.importexport.cnki.path" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="username" value=$username label="plugins.importexport.cnki.username" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" password=true id="password" value=$password label="plugins.importexport.cnki.password" maxlength="120" size=$fbvStyles.size.MEDIUM}
			{/fbvFormSection}
		{/fbvFormArea}
		{fbvFormButtons submitText="common.save" hideCancel="true"}
	</form>
</div>
