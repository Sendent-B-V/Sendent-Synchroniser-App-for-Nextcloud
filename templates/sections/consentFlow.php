<?php
script('sendentsynchroniser', '3rdparty/jscolor/jscolor');
script('sendentsynchroniser', 'settings');
style('sendentsynchroniser', ['style']);
?>
<div class="license-settings-setting-box">
    <div class="settingkeyvalue">
		<div class="labelFullWidth">
        <div style="margin-bottom:10px;" class="labelFullWidth">
		<?php if ($_['activeUser']) { ?>
			<p><?php p($l->t("You are an active user of Sendent synchroniser. You shouldn't need to do anything.")); ?></p>
			<h1 id="consentFlowTitle" style="margin-top:20px;"><?php p($l->t("Give consent")); ?></h1>
			<p id="consentFlowText"><?php p($l->t('To start the consent flow, please click on the button below.')); ?></p>
		<?php } else { ?>
			<p><?php p($l->t("We want to ask for your permission to sync Outlook content with Nextcloud to give you a unified user experience.")); ?></p>
			<h1 id="consentFlowTitle" style="margin-top:20px;"></h1>
			<p id="consentFlowText"><?php p($l->t('Click on the button below to give permission to synchronize your appointments, contacts, and tasks.')); ?></p>
		<?php } ?>
		</div>
        </div>
	</div>
</div>
<div class="actionSection">
	<input type="button" id="consentFlowButton" value='<?php p($l->t("Start consent flow")) ?>'/>
</div>
