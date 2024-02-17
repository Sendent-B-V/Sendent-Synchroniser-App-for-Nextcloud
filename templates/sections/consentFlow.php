<?php
script('sendentsynchroniser', '3rdparty/jscolor/jscolor');
script('sendentsynchroniser', 'settings');
style('sendentsynchroniser', ['style']);
?>

<?php if ($_['activeUser']) { ?>
	<div class="section" style="margin-top:-25px">
			<p><?php p($l->t("You have already succesfully provided your consent for syncing your data using the Nextcloud Exchange Connector.")); ?></p>
			</div>
				<?php } 
					

		?>
		<?php if ($_['activeUser']) { ?>
			
		<?php } 
		else { ?>
			<div class="section" style="margin-top:-25px">

			<p id="consentFlowHeader"><?php p($l->t("To ensure the seamless operation of the Nextcloud Exchange Connector, we need your permission to synchronize your Outlook with Nextcloud. This process consists of one or two simple step(s) and should only take a minute of your time.")); ?></p>
		</div>
		<?php } ?>
<div class="section" style="margin-top:-30px">
<div class="license-settings-setting-box">
    <div class="settingkeyvalue">
		<div class="labelFullWidth">
        <div style="margin-bottom:10px;" class="labelFullWidth">
		<?php if ($_['activeUser']) { ?>
			<h2 id="consentFlowTitle" style="margin-top:20px;"><?php p($l->t("Give consent")); ?></h2>
			<p id="consentFlowText"><?php p($l->t('You can refresh your consent by clicking the button below.')); ?></p>
		<?php } 
		else { ?>
			<h2 id="consentFlowTitle" style="margin-top:20px;"></h2>
			<p id="consentFlowText"><?php p($l->t('Please click the button below to sync your Outlook appointments, contacts, and tasks with Nextcloud.')); ?></p>
		<?php } ?>
		</div>
        </div>
	</div>
</div>
<div class="actionSection">
<?php if ($_['activeUser']) { ?>
	<input type="button" id="consentFlowButton" value='<?php p($l->t("Refresh consent")) ?>'/>
		<?php } 
		else { ?>
				<input type="button" id="consentFlowButton" value='<?php p($l->t("Start consent flow")) ?>'/>

				<?php } ?>
</div>
</div>