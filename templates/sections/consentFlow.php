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
			<h1 id="consentFlowTitle" style="margin-top:20px;"></h1>
			<p id="consentFlowText"><?php p($l->t('If you want to renew your consent click on the "Start consent flow" button hereunder.')); ?></p>
		<?php } else { ?>
			<p><?php p($l->t("We want to ask for your permission to sync Outlook content with Nextcloud to give you a unified user experience.")); ?></p>
			<h1 id="consentFlowTitle" style="margin-top:20px;"></h1>
			<p id="consentFlowText"><?php p($l->t('Please click on the "Start consent flow" button hereunder to start giving your permission.')); ?></p>
		<?php } ?>
		</div>
        </div>
	</div>
</div>
<div class="actionSection">
	<input type="button" id="consentFlowButton" value='<?php p($l->t("Start consent flow")) ?>'/>
</div>
