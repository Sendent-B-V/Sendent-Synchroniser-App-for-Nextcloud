<?php
script('sendentsynchroniser', '3rdparty/jscolor/jscolor');
script('sendentsynchroniser', 'settings');
style('sendentsynchroniser', ['style']);
?>

<div class="settingTemplateDetailInclude section">
    <h2>
        <?php p($l->t('Sendent Synchronizer')); ?>
    </h2>

	<div class="license-settings-setting-box">
        <div class="settingkeyvalue">
			<div class="labelFullWidth">
            <div style="margin-bottom:10px;" class="labelFullWidth">
			<?php if ($_['activeUser']) { ?>
				<p><?php p($l->t("You are an active user of Sendent synchroniser. You shouldn't need to do anything.")); ?></p>
				<p id="startConsentFlowText"><?php p($l->t('If you want to renew your consent click on the "Start consent flow" button hereunder.')); ?></p>
			<?php } else { ?>
				<p><?php p($l->t("We want to ask for your permission to sync Outlook content with Nextcloud to give you a unified user experience.")); ?></p>
				<p id="startConsentFlowText"><?php p($l->t('Please click on the "Start consent flow" button hereunder to start giving your permission.')); ?></p>
			<?php } ?>
			<p id="giveAccessCalendarText" style="display:none;"><?php p($l->t('Please click on the "Give access button" button hereunder to give your permission to synchronise your appointments, contacts, and tasks.')); ?></p>
			</div>      
        </div>
		</div>
    </div>
	<div class="actionSection">
		<input type="button" id="startConsentFlowButton" value="Start consent flow"/>
		<input type="button" id="giveAccessCalendarButton" style="display:none" value="Give access"/>
	</div>
</div>
