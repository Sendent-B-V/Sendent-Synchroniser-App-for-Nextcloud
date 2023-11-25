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
			<p id="consentFlowText"><?php p($l->t('You can refresh your consent by clicking the button below.')); ?></p>
		<?php } else { ?>
			<p id="consentFlowHeader"><?php p($l->t("To ensure the seamless operation of the Nextcloud Exchange Connector, we need your permission to synchronize your Outlook with Nextcloud. This process consists of two simple steps and should only take a minute of your time.")); ?></p>
			<h1 id="consentFlowTitle" style="margin-top:20px;"></h1>
			<p id="consentFlowText"><?php p($l->t('Please click on the button below to start the process.')); ?></p>
		<?php } ?>
		</div>
        </div>
	</div>
</div>
<div class="actionSection">
	<input type="button" id="consentFlowButton" value='<?php p($l->t("Start consent flow")) ?>'/>
</div>
