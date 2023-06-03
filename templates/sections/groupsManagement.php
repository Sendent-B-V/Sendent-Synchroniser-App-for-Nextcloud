<div class="settingTemplateDetailInclude section" id="groupsManagement">
    <h2>
        <?php p($l->t('Sendent Synchronizer')); ?>
    </h2>

	<div class="license-settings-setting-box">
        <div class="settingkeyvalue">
<div class="labelFullWidth">
            <div style="margin-bottom:10px;" class="labelFullWidth">
			<p> 
				<?php p($l->t("With the integration of Nextcloud's Groups feature, Sendent Synchroniser is as easy to use as dragging groups back and forth between 'No synchronization' and 'Active synchronization'.")); ?>
			</p>
			</div>
			<div style="margin-bottom:10px;" class="labelFullWidth">
			<p > 
				<?php p($l->t("To get started with the Sendent Synchronizer, simply select the relevant groups from the left list and drag them to the right.")); ?>
			</p>
			</div>            
        </div>
		</div>
    </div>
	<div style="display: flex; margin-top: 10px">
		<div>
			<h1>
		        <?php p($l->t('Disabled')); ?>
			</h1>
			<div style="display: flex; flex-direction: column; overflow: auto">
				<ul id="ncGroups" class="connectedSortable" style="min-height: 270px; max-height: 100%;max-width: 400px">
					<?php foreach ($_['ncGroups'] as $group) { ?>
						<li class="ui-state-default" data-gid="<?php p($group['displayName']); ?>"><?php p($group['displayName']); ?></li>
					<?php } ?>
				</ul>
			</div>
		</div>
		<div>
			<h1>
		        <?php p($l->t('Enabled')); ?>
			</h1>
			<div style="display: flex; flex-direction: column; overflow: auto">
				<ul id="sendentGroups" class="connectedSortable" style="min-height: 270px; max-height: 228px;max-width: 400px">
					<?php foreach ($_['sendentGroups'] as $group) { ?>
						<li class="ui-state-default" data-gid="<?php p($group['displayName']); ?>"><?php p($group['displayName']); ?></li>
					<?php } ?>
				</ul>
			</div>
		</div>
	</div>
</div>
