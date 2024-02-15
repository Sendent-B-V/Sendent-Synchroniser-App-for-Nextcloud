<?php
script('sendentsynchroniser', '3rdparty/jscolor/jscolor');
script('sendentsynchroniser', 'settings');
style('sendentsynchroniser', ['style']);
?>
<div class="license-settings-setting-box">
    <div class="settingkeyvalue">
		<div class="labelFullWidth">
        	<div style="margin-bottom:10px;" class="labelFullWidth">
				<h1 style="margin-top:20px;"><?php p($l->t("Retract consent")) ?></h1>
				<p><?php p($l->t('Click on the button below to retract permission to access your Exchange mailbox.')); ?></p>
			</div>
        </div>
	</div>
</div>
<div class="actionSection">
	<input type="button" id="retractConsentButton" value='<?php p($l->t("Retract consent")) ?>'/>
</div>
