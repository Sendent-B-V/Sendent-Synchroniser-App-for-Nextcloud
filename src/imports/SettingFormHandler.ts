/* eslint-disable @nextcloud/no-deprecations */
import GroupCalls from "./GroupCalls";
import CalDavCalls from "./CalDavCalls";
import MultiInputList from "./MultiInputList";
import {API} from "../common/api";
import {GroupItem} from "../common/GroupItem";
import {UserItem} from "../common/UserItem";

export default class SettingFormHandler {

    private static instance: SettingFormHandler;

    public static get(): SettingFormHandler {
        if (!this.instance) {
            this.instance = new SettingFormHandler();
        }

        return this.instance;
    }
f
private calls: GroupCalls;
private caldavCalls: CalDavCalls;
private apiCalls: API;
private logoUrl: string;

    private constructor() {
        this.calls = new GroupCalls();
        this.caldavCalls = new CalDavCalls();
        this.apiCalls = new API();
        this.logoUrl = $('#header .logo').css('background-image').replace(/url\(("|')(.+)("|')\)/gi, '$2').trim();
    }

    public async loopThroughSettings(): Promise<void> {

        $(".settingkeyvalue").each((index, element) => {
            const inputElement = $(element).find<HTMLTextAreaElement>('.settingkeyvalueinput');
            const name = $(element).find("[name='settingkeyname']").val()?.toString();
            const key = $(element).find("[name='settingkeykey']").val()?.toString();
            const templateId = $(element).find("[name='settingkeytemplateid']").val()?.toString();
            const groupId = $(element).find("[name='settinggroupid']").val()?.toString();
            const value = inputElement.val()?.toString();
            const valueType = inputElement.prop('type');

            if (!key || !name || !templateId || !valueType ||  !groupId) {
                return;
            }
            else{
                console.log("setting: " + name);

            this.handleMultiInput(inputElement, element);
            $("#btnRefreshGroupSet").on('click', async () => {
                this.handleRefreshGroupsSet(await this.apiCalls.getGroups());
            });
        }
        });

        this.setShowHideAllSettings();
    }
    private async handleMultiInput(inputElement, element)
    {
        if (inputElement.hasClass('multiValueInput')) {
            const multiInputContainer = $(element).find('.multiInputContainer');
            //const currentValue = setting.length > 0 ? setting[0].value : '';
            const groups = await this.apiCalls.getGroups();
            let groupNameString = '';
            groups.forEach(group => {
                groupNameString = groupNameString + ';' + group.id;
                console.log("group added to groupNameString:         " + group.id);
            });
            // remove the first ';'
            groupNameString = groupNameString.substring(1, groupNameString.length);
            new MultiInputList(multiInputContainer, groupNameString, inputElement);
        }
    }
    private async handleRefreshGroupUserSet(userItems: UserItem[])
    {
        let username = $("#useraccount").val() as string;

    }
    private async handleRefreshGroupsSet(groupList: GroupItem[])
    {
            let membershipList = '';
            groupList.forEach(group => {
                membershipList = membershipList + '<li>' + group.id + '</li>';
                console.log("group added to membershipList:         " + group.id);
            });
            $("#groupForSynchronisation").html('<ul class="settingkeyvalueinput" name="settingkeyvalueinput" id="membershipresult"></ul>');
            $("#groupForSynchronisation").append(membershipList);
        }
    

    public async saveSetting(settingbox: JQuery<HTMLElement>): Promise<boolean> {
        const settingkeyvalueblock = $(settingbox).find(".settingkeyvalue")[0]; //@TODO

        //@TODO move to method
        const id = $(settingkeyvalueblock).find("[name='settingkeyid']").val()?.toString();
        const name = $(settingkeyvalueblock).find("[name='settingkeyname']").val()?.toString();
        const key = $(settingkeyvalueblock).find("[name='settingkeykey']").val()?.toString();
        const groupId = $(settingkeyvalueblock).find("[name='settinggroupid']").val()?.toString();
        const value = $(settingkeyvalueblock).find(".settingkeyvalueinput").val()?.toString();

        console.log("settingkeyname     = " + name);
        console.log("settingkeykey      = " + key);
        console.log("settingkeyvalue    = " + value);

        if (!id || !groupId || typeof value !== 'string') {
            return false;
        }

        
        const statusElement = $(settingkeyvalueblock).find(".status-ok")[0];
        $(statusElement).removeClass("hidden").addClass("shown").delay(1000).queue(function (next) {
            $(this).addClass("hidden");
            $(this).removeClass("shown")
            next();
        });

        return true;
    }

    private setShowHideAllSettings(): void {
        const personalSettingBoxes = $(".personal-settings-setting-box");

        personalSettingBoxes.each((_, settingbox) => {
            const values = $(settingbox).find(".settingkeyvalueinput");
            const settingkeyid = $(settingbox).find("[name='settingkeyname']").val()?.toString();

            if (!settingkeyid) {
                return;
            }

            values.each(() => {
                this.showHideAttachmentSize(values, settingkeyid);
                this.showHideAdvancedTheming(values, settingkeyid);
            });
        });
    }

    private showHideAttachmentSize(settingkeyvalues: JQuery<HTMLElement>, settingkeyid: string): void {
        const settingkeyvalue = settingkeyvalues.val();

        if (settingkeyid == "attachmentmode") {
            if (settingkeyvalue == "MaximumAttachmentSize") {
                $(".personal-settings-setting-box#attachmentsize").removeClass("hidden").addClass("shown");
            }
            else {
                $(".personal-settings-setting-box#attachmentsize").addClass("hidden").removeClass("shown");
            }
        }
        else if (settingkeyid == "sendmode") {
            if (settingkeyvalue == "Separate") {
                $(".personal-settings-setting-box#htmlsnippetpassword").removeClass("hidden").addClass("shown");
            }
            else {
                $(".personal-settings-setting-box#htmlsnippetpassword").addClass("hidden").removeClass("shown");
            }
        }
    }

    private showHideAdvancedTheming(settingkeyvalues: JQuery<HTMLElement>, settingkeyid: string): void {
        const settingkeyvalue = settingkeyvalues.val();

        if (settingkeyid == "AdvancedThemingEnabled") {
            if (settingkeyvalue == "true") {
                $(".advancedTheming").removeClass("hidden").addClass("shown");
            }
            else {
                $(".advancedTheming").addClass("hidden").removeClass("shown");
            }
        }
    }

    private updateUI(settingbox: JQuery<HTMLElement>): void {
        const keyValue = $(settingbox).find(".settingkeyvalueinput").first().val()?.toString();
        const keyId = $(settingbox).find("[name='settingkeyname']").val()?.toString();

        if (!keyId || typeof keyValue !== 'string') {
            return;
        }

        if (keyId === "attachmentmode") {
            if (keyValue === "MaximumAttachmentSize") {
                $(".personal-settings-setting-box.attachmentSize").removeClass("hidden").addClass("shown");
            } else {
                $(".personal-settings-setting-box.attachmentSize").addClass("hidden").removeClass("shown");
            }
        }
        else if (keyId === "sendmode") {
            if (keyValue == "Separate") {
                $(".personal-settings-setting-box.htmlSnippetPassword").removeClass("hidden").addClass("shown");
            } else {
                $(".personal-settings-setting-box.htmlSnippetPassword").addClass("hidden").removeClass("shown");
            }
        } else if (keyId === "AdvancedThemingEnabled") {
            if (keyValue === "true") {
                $(".advancedTheming").removeClass("hidden").addClass("shown");
            }
            else {
                $(".advancedTheming").addClass("hidden").removeClass("shown");
            }
        }
    }
}
