<?php
/** @var $l \OCP\IL10N */
/** @var $_ array */
?>
<div class="settingTemplateDetailExclude section">
    <h2>
        <?php p($l->t('Settings')); ?>
    </h2>
    <div class="personal-settings-setting-box">
        <div class="settingkeyvalue">
            <label>
                <span class="templatesettingkeyname">
                    <?php p($l->t('Expired token notification method')); ?>
                </span>
            </label>
            <div class="status-error icon-error error hidden"></div>
            <div class="status-ok icon-checkmark ok hidden"></div>
            <select class="settingkeyvalueinput" type="select" id="setNotificationMethod">
                <option value="1" <?php ($_['notificationMethod']==1) ? p('selected') : ''; ?> >Show in Mail, Calendar, Contacts, and Tasks</option>
                <option value="2" <?php ($_['notificationMethod']==2) ? p('selected') : ''; ?> >Show in Files</option>
                <option value="3" <?php ($_['notificationMethod']==3) ? p('selected') : ''; ?> >Show everywhere (options 1 and 2 combined)</option>
            </select>
        </div>
    </div>
    <div class="license-settings-setting-box">
        <div class="settingkeyvalue">
            <label>
                <span class="templatesettingkeyname">
                    <?php p($l->t('Shared secret')); ?></span>
            </label>
            <div class="status-error icon-error error hidden"></div>
            <div class="status-ok icon-checkmark ok hidden"></div>
			<input class="settingkeyvalueinput" type="password" id="setSharedSecret" value="<?php p($_['sharedSecret']); ?>" autocapitalize="none" autocorrect="off">
            <button id="showSharedSecret" style="padding:0;min-width:36px"><img src="<?php print_unescaped(image_path('sendentsynchroniser', 'view.svg')); ?>" style="height:22px;width:22px" /></button>
        </div>
    </div>
</div>
