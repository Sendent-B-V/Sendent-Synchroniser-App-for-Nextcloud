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
            <select class="settingkeyvalueinput" type="select" name="settingkeyvalueinput" id="setlanguage">
                <option value="1" selected>Show in Mail, Calendar, Contacts, and Tasks</option>
                <option value="2">Show in Files</option>
                <option value="3">Show everywhere (options 1 and 2 combined)</option>
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
            <input class="settingkeyvalueinput" name="licensekeyvalueinput" id="licenseEmail" value="" autocapitalize="none" autocorrect="off">
        </div>
    </div>
</div>
