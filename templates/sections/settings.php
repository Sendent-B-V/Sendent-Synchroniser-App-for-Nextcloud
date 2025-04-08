<?php
/** @var $l \OCP\IL10N */
/** @var $_ array */
use OCA\SendentSynchroniser\Constants;
?>
<div class="settingTemplateDetailExclude section">
    <h2>
        <?php p($l->t('Settings')); ?>
    </h2>
    <div class="license-settings-setting-box">
        <div class="settingkeyvalue">
            <label>
                <span class="templatesettingkeyname">
                    <?php p($l->t('Shared secret')); ?></span>
            </label>
            <div id="sharedSecretChangedOk" class="status-ok icon-checkmark ok hidden"></div>
            <div id="sharedSecretChangedKo" class="status-error icon-error error hidden"></div>
			<input class="settingkeyvalueinput" type="password" id="setSharedSecret" value="<?php p($_['sharedSecret']); ?>" autocapitalize="none" autocorrect="off">
            <button id="showSharedSecret" style="padding:0;min-width:36px"><img src="<?php print_unescaped(image_path('sendentsynchroniser', 'view.svg')); ?>" style="height:22px;width:22px" /></button>
        </div>
    </div>
    
    <div class="personal-settings-setting-box">
        <div class="settingkeyvalue">
            <label>
                <span class="templatesettingkeyname">
                    <?php p($l->t('Enable IMAP synchronisation')); ?>
                </span>
            </label>
            <div id="IMAPSyncChangedOk" class="status-ok icon-checkmark ok hidden"></div>
            <div id="IMAPSyncChangedKo" class="status-error icon-error error hidden"></div>
            <select class="settingkeyvalueinput" type="select" id="setIMAPSyncEnabled" <?php $_['mailAppInstalled'] ? '' : p('disabled=disabled style=color:var(--color-placeholder-light);border-color:var(--color-border)') ?> >
                <option value="true" <?php $_['IMAPSyncEnabled'] ? p('selected') : ''; ?> ><?php p($l->t('Enabled')); ?></option>
                <option value="false" <?php !$_['IMAPSyncEnabled'] ? p('selected') : ''; ?> ><?php p($l->t('Disabled')); ?></option>
            </select>
            <label <?php $_['mailAppInstalled'] ? p('style=display:none') : '' ?> >
                <span class="settingkeyvalueinheritedlabel" style="color:var(--color-error-hover);font-style:italic"><?php p($l->t('You don\'t have the mail app installed'));?></span>
            </label>
        </div>
    </div>
    <div id="emailDomainSetting" class="license-settings-setting-box">
        <div class="settingkeyvalue">
            <label>
                <span class="templatesettingkeyname">
                    <?php p($l->t('Email domain')); ?></span>
            </label>
            <div id="emailDomainChangedOk" class="status-ok icon-checkmark ok hidden"></div>
            <div id="emailDomainChangedOk" class="status-error icon-error error hidden"></div>
			<input class="settingkeyvalueinput" id="setEmailDomain" placeholder="acme.com" value="<?php p($_['emailDomain']); ?>" autocapitalize="none" autocorrect="off">
        </div>
    </div>
    <h1>
        <?php p($l->t('Enrollment reminders')); ?>
    </h1>
    <div class="personal-settings-setting-box">
        <div class="settingkeyvalue">
            <label>
                <span class="templatesettingkeyname">
                    <?php p($l->t('Reminder type')); ?>
                </span>
            </label>
			<div id="enrollmentReminderChangedOk" class="status-ok icon-checkmark ok hidden"></div>
            <div id="enrollmentReminderChangedKo"class="status-error icon-error error hidden"></div>
            <select class="settingkeyvalueinput" type="select" id="setReminderType">
                <option value="1" <?php ($_['reminderType']==Constants::REMINDER_MODAL) ? p('selected') : ''; ?> >Modal dialog</option>
                <option value="2" <?php ($_['reminderType']==Constants::REMINDER_NOTIFICATIONS) ? p('selected') : ''; ?> <?php $_['notificationsAppInstalled'] ? '' : p('disabled=disabled') ?> >Standard notifications</option>
                <option value="3" <?php ($_['reminderType']==Constants::REMINDER_BOTH) ? p('selected') : ''; ?> <?php $_['notificationsAppInstalled'] ? '' : p('disabled="disabled"') ?> >Modal dialog and standard notifications</option>
            </select>
            <label <?php $_['notificationsAppInstalled'] ? p('style=display:none') : '' ?> >
                <span class="settingkeyvalueinheritedlabel" style="color:var(--color-error-hover);font-style:italic"><?php p($l->t('You don\'t have the notifications app installed'));?></span>
            </label>
        </div>
    </div>
    <div class="personal-settings-setting-box">
        <div class="settingkeyvalue">
            <label>
                <span class="templatesettingkeyname">
                    <?php p($l->t('Applications to show modal dialog on')); ?>
                </span>
            </label>
            <div id="ModalNotificationChangedOk"class="status-ok icon-checkmark ok hidden"></div>
            <div id="ModalNotificationChangedKo"class="status-error icon-error error hidden"></div>
            <select class="settingkeyvalueinput" type="select" id="setNotificationMethod">
                <option value="1" <?php ($_['notificationMethod']==Constants::NOTIFICATIONMETHOD_MODAL_GROUPWARE) ? p('selected') : ''; ?> >Show in Mail, Calendar, Contacts, and Tasks</option>
                <option value="2" <?php ($_['notificationMethod']==Constants::NOTIFICATIONMETHOD_MODAL_FILE) ? p('selected') : ''; ?> >Show in Files</option>
                <option value="3" <?php ($_['notificationMethod']==Constants::NOTIFICATIONMETHOD_MODAL_BOTH) ? p('selected') : ''; ?> >Show everywhere (options 1 and 2 combined)</option>
            </select>
        </div>
    </div>
    <div class="personal-settings-setting-box">
        <div class="settingkeyvalue">
            <label>
                <span class="templatesettingkeyname">
                    <?php p($l->t('Notifications interval in days')); ?>
                </span>
            </label>
            <div id="NotificationsIntervalChangedOk"class="status-ok icon-checkmark ok hidden"></div>
            <div id="NotificationsIntervalChangedKo"class="status-error icon-error error hidden"></div>
            <input class="settingkeyvalueinput" id="setNotificationInterval" value="<?php p($_['notificationInterval']) ?>" <?php $_['notificationsAppInstalled'] ? '' : p('disabled=disabled style=color:var(--color-placeholder-light);border-color:var(--color-border)') ?>>
            <label <?php $_['notificationsAppInstalled'] ? p('style=display:none') : '' ?> >
                <span class="settingkeyvalueinheritedlabel" style="color:var(--color-error-hover);font-style:italic"><?php p($l->t('You don\'t have the notifications app installed'));?></span>
            </label>
        </div>
    </div>
</div>
