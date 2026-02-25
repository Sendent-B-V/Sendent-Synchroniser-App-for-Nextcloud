<div class="settingTemplateDetailInclude section" id="groupsManagement">
    <h2>
        <?php p($l->t('Sendent Sync Groups')); ?>
    </h2>

	<div class="license-settings-setting-box">
        <div class="settingkeyvalue">
			<div class="labelFullWidth">
				<div class="labelFullWidth">
					<p>
						<?php p($l->t("With the integration of Nextcloud's Groups feature, Sendent Sync is as easy to use as dragging groups back and forth between 'Inactive' and 'Active'.")); ?>
					</p>
				</div>
				<div class="labelFullWidth">
					<p>
						<?php p($l->t("To get started with Sendent Sync, simply select the relevant groups from the left list and drag them to the right.")); ?>
					</p>
				</div>
			</div>
		</div>
    </div>
	<div style="display: flex">
		<div>
			<h1>
		        <?php p($l->t('Inactive')); ?>
			</h1>
			<input id="ncGroupsFilter" type="text" placeholder="Filter list.." style="min-width: 265px">
			<div style="display: flex; flex-direction: column; overflow: auto">
				<ul id="ncGroups" class="connectedSortable" style="min-height: 270px; max-height: 400px; max-width: 400px; overflow-y: auto">
					<?php foreach ($_['ncGroups'] as $group) { ?>
						<li class="ui-state-default" data-gid="<?php p($group['gid']); ?>"><?php p($group['displayName']); ?></li>
					<?php } ?>
				</ul>
			</div>
		</div>
		<div>
			<h1>
		        <?php p($l->t('Active')); ?>
			</h1>
			<input id="sendentGroupsFilter" type="text" placeholder="Filter list.." style="min-width: 265px">
			<div style="display: flex; flex-direction: column; overflow: auto">
				<ul id="sendentGroups" class="connectedSortable" style="min-height: 270px; max-height: 400px; max-width: 400px; overflow-y: auto">
					<?php foreach ($_['sendentGroups'] as $group) { ?>
						<li class="ui-state-default" data-gid="<?php p($group['gid']); ?>"><?php p($group['displayName']); ?></li>
					<?php } ?>
				</ul>
			</div>
		</div>
	</div>
	<h3 style="margin-bottom: 0px;font-weight: bold">
        <?php p($l->t('User management')); ?>
    </h3>
	<div class="license-settings-setting-box">
        <div class="settingkeyvalue">
			<div class="labelFullWidth">
				<div style="margin-top:10px;" class="labelFullWidth">
					<p style="margin-bottom: 10px">
						<?php p($l->t('You have enabled Sendent Sync for %1$s user(s), and it is currently used by %2$s user(s).', [$_['nbEnabledUsers'],$_['nbActiveUsers']])); ?>
					</p>
					<p>
						<?php p($l->t('To send a notification to non-active user(s) to remind them to setup their synchronisation, click the "Remind users" button below.')); ?>
					</p>
					<p>
						<?php p($l->t('To clear the synchronisation token of active users, and force them to re-generate one, click the "Clear tokens" button below.')); ?>
					</p>
				</div>
			</div>
		</div>
	</div>
	<div style="display: flex; margin-top: 10px">
		<div class="license-settings-setting-box" style="display:flex;align-items: center;margin-right: 10px">
        	<div class="settingkeyvalue">
	            <input type="button" id="btnRemindUsers" value="Remind users" <?php $_['notificationsAppInstalled'] ? '' : p('disabled=disabled') ?>>
        	</div>
			<label <?php $_['notificationsAppInstalled'] ? p('style=display:none') : '' ?> >
            	    <span class="settingkeyvalueinheritedlabel" style="color:var(--color-error-hover);font-style:italic"><?php p($l->t('You don\'t have the notifications app installed'));?></span>
        	</label>
    	</div>
		<div class="license-settings-setting-box">
        	<div class="settingkeyvalue">
	            <input type="button" id="btnClearTokens" value="Clear tokens">
        	</div>
    </div>
</div>
