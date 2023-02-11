<div class="settingTemplateDetailInclude section" id="generalsettings">
    <h1>
        <?php p($l->t('Synchronisation settings')); ?>
    </h1>
    <div class="personal-settings-setting-box">
        <div class="settingkeyvalue">
            <label>
                <span class="templatesettingkeyname">
                    <?php p($l->t('Serviceaccount')); ?>
                </span>
            </label>
            <div class="status-error icon-error error hidden"></div>
            <div class="status-ok icon-checkmark ok hidden"></div>
            <input class="settingkeyvalueinput" type="text" name="settingkeyvalueinput" id="serviceacc"/>
            <input type="hidden" name="settingkeyname" value="serviceacc">
            <input type="hidden" name="settingkeykey" value="1">
            <input type="hidden" name="settingkeytemplateid" value="0">
            <input type="hidden" name="settinggroupid" value="0">
            <input type="hidden" name="settingkeyid" value="1">
        </div>
    </div>
    
    <div class="personal-settings-setting-box">
        <div class="settingkeyvalue">
            <label>
                <span class="templatesettingkeyname">
                    <?php p($l->t('Groups to be synchronised')); ?>
                </span>
            </label>
            <div class="status-error icon-error error hidden"></div>
            <div class="status-ok icon-checkmark ok hidden"></div>
            <input class="settingkeyvalueinput multiValueInput" type="text" name="settingkeyvalue" id="groupForSynchronisation"
                value="" placeholder="Select groups to enable synchronisation for" autocomplete="on"
                autocapitalize="none" autocorrect="off">
            <div class="multiInputContainer"></div>
            <input type="hidden" name="settingkeyname" value="groupForSynchronisation">
            <input type="hidden" name="settingkeytemplateid" value="0">
            <input type="hidden" name="settinggroupid" value="0">
            <input type="hidden" name="settingkeykey" value="0">
            <input type="hidden" name="settingkeyid" value="0">
        </div>
    </div>
    <div class="debug">
        <div class="personal-settings-setting-box">
            <div class="settingkeyvalue">
                <label>
                    <span class="templatesettingkeyname">
                        <?php p($l->t('Debug tool for membershipresults')); ?>
                    </span>
                </label>
                <div class="status-error icon-error error hidden"></div>
                <div class="status-ok icon-checkmark ok hidden"></div>
                <input class="settingkeyvalueinput" type="text" name="settingkeyvalueinput" id="useraccount"/>
                <input class="settingkeyvalueinput" type="button" name="settingkeyvalueinput" id="btnRefreshGroupSet"/>
                <input type="hidden" name="settingkeyname" value="useraccount">
                <input type="hidden" name="settingkeykey" value="22">
                <input type="hidden" name="settingkeytemplateid" value="0">
                <input type="hidden" name="settinggroupid" value="0">
                <input type="hidden" name="settingkeyid" value="22">
            </div>
        </div>
        <div class="personal-settings-setting-box">
            <div class="settingkeyvalue">
                <label>
                    <span class="templatesettingkeyname">
                        <?php p($l->t('Debug: membershipresult')); ?>
                    </span>
                </label>
                <div class="status-error icon-error error hidden"></div>
                <div class="status-ok icon-checkmark ok hidden"></div>
                
                <ul class="settingkeyvalueinput" name="settingkeyvalueinput" id="membershipresult"></ul>
                <input type="hidden" name="settingkeyname" value="membershipresult">
                <input type="hidden" name="settingkeykey" value="22">
                <input type="hidden" name="settingkeytemplateid" value="0">
                <input type="hidden" name="settinggroupid" value="0">
                <input type="hidden" name="settingkeyid" value="22">
            </div>
        </div>
    </div>
</div>
