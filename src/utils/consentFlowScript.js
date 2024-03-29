import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'


export function hideConsentFlowItems(){
	// $("#Consent-Flow").fadeOut(0);
	// $("#Consent-Flow").animate({left:'-200px'}, 0);

	//$("#consentFlowHeader").fadeOut(0);
	//$("#consentFlowHeader").animate({left:'-200px'}, 0);

	
	// $("#Consent-Flow").fadeOut(0); 
    // $("#Consent-Flow").animate({width: '0px'}, 500);

	$("#consentFlowTitle").fadeOut(0); 

	$("#consentFlowText").fadeOut(0); 

	$("#consentFlowButton").fadeOut(0); 
}
export function showConsentFlowItems(){

	// $("#Consent-Flow").fadeIn(300);
    // $("#Consent-Flow").animate({width: '500px'}, 500);

	$("#consentFlowTitle").fadeIn(1000);
 
	$("#consentFlowText").fadeIn(1000);

	$("#consentFlowButton").fadeIn(1000);
}
export function activateConsentFlowDialog() {
	$('#consentFlowButton').on('click', function(e) {
		
		hideConsentFlowItems();
		$('#consentFlowButton').val(t("sendentsynchroniser", "Give access"))
		$('#consentFlowHeader').val("");
		$('#consentFlowTitle').text(t("sendentsynchroniser", "Step 1: Set up appointments, contacts, and tasks"))
		$('#consentFlowText').text(t("sendentsynchroniser", 'Please click the button below to allow synchronisation of your Outlook appointments, contacts, and tasks with Nextcloud.'))
		$('#consentFlowButton').off()
		showConsentFlowItems();

		$('#consentFlowButton').on('click', async function() {
		
		// Try to activate user for calendar and contacts synchronisation
			let url = generateUrl('/apps/sendentsynchroniser/api/1.0/user/activate');
			let response = await axios.get(url)
			if (response.status !== 200) {
				console.warn('Error while trying to activate user', resp)
				return
			}

			// Configure mail sync
			if (response.data.shouldAskMailSync) {

				let domain = response.data.emailDomain
				// Admin wants mails to be synced and the Mail app is installed, let's see if the user has already setup mail sync
				url = generateUrl('/apps/mail/api/accounts');
				response = await axios.get(url)
				if (response.status !== 200) {
					console.warn('Error while trying to get current status of IMAP synchronisation', resp)
					return
				}

				if (response.data.length === 0) {
					
					hideConsentFlowItems();
					// User has no Mail account, sure mail sync isn't set
					$('#consentFlowTitle').text(t("sendentsynchroniser", "Step 2: Set up mail"))
					$('#consentFlowText').text(t("sendentsynchroniser", 'Your Outlook appointments, contacts, and tasks are now synchronised with Nextcloud. By clicking the button below you\'ll be redirected to the Mail application to set it up.'))
					$('#consentFlowButton').val(t("sendentsynchroniser", "Finish"))
					$('#consentFlowButton').off().on('click', function() { window.open(generateUrl('/apps/mail'), '_self')})
					showConsentFlowItems();

				} else {
					// User has some Mail account(s), let's see if one of them maps to the domain that the admin wants
					let account = response.data[0]
					if (account.emailAddress.endsWith(domain)) {
						hideConsentFlowItems();
						$('#consentFlowTitle').text(t("sendentsynchroniser", "Configuration complete"))
						$('#consentFlowText').text(t("sendentsynchroniser", 'Your Outlook appointments, contacts, and tasks are now synchronised with Nextcloud. And, your Exchange mailbox seems properly setup in the Mail application. You may close this window'))
						$('#consentFlowButton').val(t("sendentsynchroniser", "Close"))
						$('#consentFlowButton').off().on('click', function() {$('#sendentSyncModal').hide()})
						showConsentFlowItems();

					} else {
						hideConsentFlowItems();
						$('#consentFlowTitle').text(t("sendentsynchroniser", "Step 2: Set up mail"))
						$('#consentFlowText').text(t("sendentsynchroniser", 'Your Outlook appointments, contacts, and tasks are now synchronised with Nextcloud. But, your Exchange mailbox doesn\'t seem properly setup in the Mail application. Please click the button below to grant permission for accessing your Exchange mailbox.'))
						$('#consentFlowButton').val(t("sendentsynchroniser", "Finish"))
						$('#consentFlowButton').off().on('click', function() { window.open(generateUrl('/apps/mail'), '_self')})
						showConsentFlowItems();
					}
				}
			} else {
				// Admin doesn't wants mails to be synced (or Mail app is not installed)
				hideConsentFlowItems();
				$('#consentFlowButton').hide()
				$('#consentFlowTitle').text(t("sendentsynchroniser", "Configuration complete"))
				$('#consentFlowText').text(t("sendentsynchroniser", 'Your account is fully configured for Exchange synchronization. You may close this window'))
				$('#consentFlowButton').val(t("sendentsynchroniser", "Close"))
				$('#consentFlowButton').off().on('click', function() {$('#sendentSyncModal').hide()})
				showConsentFlowItems();
			}
		})
	})
}
