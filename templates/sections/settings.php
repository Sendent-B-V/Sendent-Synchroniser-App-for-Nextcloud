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
                    <?php p($l->t('Enrollment reminders')); ?>
                </span>
            </label>
			<div id="enrollmentReminderChangedOk" class="status-ok icon-checkmark ok hidden"></div>
            <div id="enrollmentReminderChangedKo"class="status-error icon-error error hidden"></div>
            <select class="settingkeyvalueinput" type="select" id="setReminderType">
                <option value="1" <?php ($_['reminderType']==Constants::REMINDER_MODAL) ? p('selected') : ''; ?> >Modal dialog</option>
                <option value="2" <?php ($_['reminderType']==Constants::REMINDER_NOTIFICATIONS) ? p('selected') : ''; ?> >Standard notifications</option>
                <option value="3" <?php ($_['reminderType']==Constants::REMINDER_BOTH) ? p('selected') : ''; ?> >Modal dialog and standard notifications</option>
            </select>
        </div>
    </div>
    <h1>
        <?php p($l->t('Modal dialog enrollment reminder settings')); ?>
    </h1>
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
    <h1>
        <?php p($l->t('Standard notification enrollment reminder settings')); ?>
    </h1>
    <div class="personal-settings-setting-box">
        <div class="settingkeyvalue">
            <label>
                <span class="templatesettingkeyname">
                    <?php p($l->t('Notifications interval in days')); ?>
                </span>
            </label>
            <div id="NotificationsIntervalChangedOk"class="status-ok icon-checkmark ok hidden"></div>
            <div id="NotificationsIntervalChangedKo"class="status-error icon-error error hidden"></div>
            <input class="settingkeyvalueinput" id="setNotificationInterval" value="<?php p($_['notificationInterval']) ?>">
        </div>
    </div>
</div>
