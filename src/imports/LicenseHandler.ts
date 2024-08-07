/* eslint-disable @nextcloud/no-deprecations */
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import { translate as t } from '@nextcloud/l10n'

type LicenseStatus = {
    status: string
    statusKind: string,
    dateExpiration: string,
    email: string,
    level: string,
    licensekey: string,
    product: string,
    dateLastCheck: string,
    LatestVSTOAddinVersion : AppVersionStatus,
    istrial : number
}
type AppVersionStatus = {
    ApplicationName : string
    ApplicationId : string
    Version : string
    UrlBinary: string
    UrlManual: string,
    UrlReleaseNotes : string,
    ReleaseDate: string
}
export default class LicenseHandler {
    private static instance: LicenseHandler;

    private constructor() {
        $("#btnLicenseActivation").on('click', (ev) => {
            ev.preventDefault();

            const email = $("#licenseEmail").val()?.toString().replace(/\s+/g, '') || '';
            const key = $("#licensekey").val()?.toString().replace(/\s+/g, '') || '';

            this.createLicense(email, key);
        });

        $("#btnClearLicense").on('click', (ev) => {
            $("#licenseEmail").val('');
            $("#licensekey").val('');
            this.createLicense('', '');
        });

        $("#licenseEmail, #licensekey").on('change', () => {
            $("#btnLicenseActivation").val(t("sendent", "Activate license"));
            $("#btnLicenseActivation").removeClass("hidden").addClass("shown");
        });

        $('#btnSupportButton').on('click', () => {
            (window as any).location = 'mailto:support@sendent.nl';
        });
    }

    public static setup(): LicenseHandler {
        if (!this.instance) {
            this.instance = new LicenseHandler();

            this.instance.refreshLicenseStatus();
        }

        return this.instance;
    }

    public async createLicense(email: string, key: string): Promise<void> {
        this.disableButtons();

        try {
            await this.sendCreationRequest(email, key);
        } catch (err) {
            console.log('Could not create license', err);
        }

        this.enableButtons();

        return this.refreshLicenseStatus();
    }

    public async refreshLicenseStatus(): Promise<void> {
        this.insertLoadIndicator('#licensestatus, #latestVSTOVersion, #licenselastcheck, #licenseexpires, #licenselevel');
        this.disableButtons();

		console.log('Refreshing license status');
        try {
            const { data: status } = await this.requestStatus();
            const offline_mode_text = "Undetermined because Offline mode is used.";

            let statusdateLastCheckDate = new Date(status.dateLastCheck);
            let statusdateLastCheckDateString = status.level == "Offline_mode" ? offline_mode_text : statusdateLastCheckDate.toLocaleDateString('nl-NL', { timeZone: 'UTC' });
            let statusdateExpirationDate = new Date(status.dateExpiration);
            let statusdateExpirationDateString = status.level == "Offline_mode" ? offline_mode_text : statusdateExpirationDate.toLocaleDateString('nl-NL', { timeZone: 'UTC' });
            let statusSubscriptionType = status.level == "Offline_mode" ? offline_mode_text : status.istrial == 1 ? "Trial" : status.istrial == 0 ? "Paid subscription" : "Subscription type can't be determined";
            let statusSubscriptionLevel = status.level == "Offline_mode" ? offline_mode_text : status.level == '0' || status.level == ''|| status.level == null ? status.product : status.level;

            $("#licensestatus").html(status.status);
            $("#licenselastcheck").text(statusdateLastCheckDateString);
            $("#licenseexpires").text(statusdateExpirationDateString);
            $("#licenselevel").html(statusSubscriptionLevel);
            $("#licenseEmail").val(status.email);
            $("#licensekey").val(status.licensekey);
            
            this.updateStatus(status.statusKind);
            this.updateButtonStatus(status.statusKind);

			// We are showing the default license
			$("#licensekey").next().removeClass('settingkeyvalueinherited');
			$("#licensekey").prop('disabled', false);
			$("#licenseEmail").prop('disabled', false);
		    this.enableButtons();
			

			// Sets up inheritance checkbox's action
            $("#licensekey").next().find("input").off('change')
            $("#licensekey").next().find("input").on('change', (ev) => {
				if ($("#licensekey").next().find('input:checked').val()) {
					this.deleteLicense();
					this.refreshLicenseStatus().then(() => {
						$("#licensekey").prop('disabled', true);
						$("#licenseEmail").prop('disabled', true);
						this.disableButtons()
					});
	            } else {
					$("#licensekey").prop('disabled', false);
					$("#licenseEmail").prop('disabled', false);
					this.createLicense('', '');
					this.enableButtons();
                }
            });

			// Makes sure the buttons' click action act on the correct group
			$("#btnLicenseActivation").off('click')
			$("#btnLicenseActivation").on('click', (ev) => {
	            ev.preventDefault();
	            const email = $("#licenseEmail").val()?.toString().replace(/\s+/g, '') || '';
	            const key = $("#licensekey").val()?.toString().replace(/\s+/g, '') || '';
	            this.createLicense(email, key);
	        });
		    $("#btnClearLicense").off('click')
		    $("#btnClearLicense").on('click',  (ev) => {
				$("#licenseEmail").val('');
		        $("#licensekey").val('');
	            this.createLicense('', '');
	        });
            this.hideValuesForOffline(status);

        } catch (err) {
            console.warn('Error while fetching license status', err);

            $("#licensestatus").text(t("sendent", "Cannot verify your license. Please make sure your licensekey and emailaddress are correct before you try to 'Activate license'."));
            $("#licenselastcheck").text(t("sendent", "Just now"));
            $("#licenseexpires, #licenselevel").text("-");

            this.showErrorStatus();

            $("#btnLicenseActivation").val(t("sendent", "Activate license"));
            $("#btnLicenseActivation").removeClass("hidden").addClass("shown");
            $("#btnSupportButton").removeClass("shown").addClass("hidden");

	        this.enableButtons();
        }

        //Remove exchange connector for now.
        $("tr[id*='outlook']").each(function (i, el) {
            $(this).removeClass("shown").addClass("hidden");
        });
        $("tr[id*='teams']").each(function (i, el) {
            $(this).removeClass("shown").addClass("hidden");
        });
		return;
    }

    private insertLoadIndicator(selector: string) {
        $(selector).html('<div class="spinner"> <div class="bounce1"></div> <div class="bounce2"></div> <div class="bounce3"></div></div>');
    }

    private updateStatus(kind: string) {
        if (['valid'].includes(kind)) {
            this.showOkStatus();
        } else if (['check', 'nolicense', 'userlimit'].includes(kind)) {
            this.showWarningStatus();
        } else {
            this.showErrorStatus();
        }
    }

    private updateButtonStatus(kind: string) {
        if (kind === 'valid') {
            $("#btnLicenseActivation")
                .removeClass("shown")
                .addClass("hidden")
                .val(t("sendent", "Activate license"));
        } else if (['check', 'expired', 'userlimit'].includes(kind)) {
            $("#btnLicenseActivation")
                .removeClass("hidden")
                .addClass("shown")
                .val(t("sendent", "Revalidate license"));
        } else {
            $("#btnLicenseActivation")
                .removeClass("hidden")
                .addClass("shown")
                .val(t("sendent", "Activate license"));
        }
    }
    private hideValuesForOffline(status: LicenseStatus) {
        if (status.level === 'Offline_mode') {
            $(".subscriptionInformation").removeClass("shown").addClass("hidden");
            $('#licenseOfflineMessage').removeClass("hidden").addClass("shown");
        }
        else{
            $(".subscriptionInformation").removeClass("hidden").addClass("shown");
            $('#licenseOfflineMessage').removeClass("shown").addClass("hidden");
        }
        if(status.level == '' || status.level == status.product ||  status.product.toLowerCase().includes(status.level.toLowerCase()))
            {
                $("#licenselevelcontainer").removeClass("shown").addClass("hidden");
                $("#defaultlicenselevelcontainer").removeClass("shown").addClass("hidden");
            }
    }
    private showErrorStatus() {
        $("#license .licensekeyvalueinput").addClass("errorStatus").removeClass("okStatus warningStatus");
    }

    private showWarningStatus() {
        $("#license .licensekeyvalueinput").addClass("warningStatus").removeClass("okStatus errorStatus");
    }

    private showOkStatus() {
        $("#license .licensekeyvalueinput").addClass("okStatus").removeClass("errorStatus warningStatus");
    }

    private disableButtons() {
        $("#btnSupportButton, #btnClearLicense, #btnLicenseActivation").prop('disabled', true);
    }

    private enableButtons() {
        $("#btnSupportButton, #btnClearLicense, #btnLicenseActivation").removeAttr("disabled");
    }

	private deleteLicense() {
        const url = generateUrl('/apps/sendentsynchroniser/api/1.0/license');

        return axios.delete(url);
	}

    private sendCreationRequest(email: string, license: string) {
        const url = generateUrl('/apps/sendentsynchroniser/api/1.0/license');

        return axios.post(url, { email, license });
    }

    private requestStatus() {

        const url = generateUrl('/apps/sendentsynchroniser/api/1.0/licensestatusinternal');

        return axios.get<LicenseStatus>(url);
    }
    private requestApplicationStatus() {
        const url = generateUrl('/apps/sendentsynchroniser/api/1.0/status');

        return axios.get<LicenseStatus>(url);
    }

}
