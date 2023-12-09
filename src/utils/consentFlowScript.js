import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'

function configDone() {
	$('#consentFlowButton').hide()
	$('#consentFlowTitle').text(t("sendentsynchroniser", "Configuration complete"))
	$('#consentFlowText').text(t("sendentsynchroniser", 'Your account is fully configured for Exchange synchronization.'))
	setTimeout(() => {location.reload(),5000})
}

export function activateConsentFlowDialog() {
	$('#consentFlowButton').on('click', function(e) {
		$('#consentFlowButton').val(t("sendentsynchroniser", "Give access"))
		$('#consentFlowHeader').val("");
		$('#consentFlowTitle').text(t("sendentsynchroniser", "Step 1: Set up appointments, contacts, and tasks"))
		$('#consentFlowText').text(t("sendentsynchroniser", 'Please click the button below to sync your Outlook appointments, contacts, and tasks with Nextcloud.'))
		$('#consentFlowButton').off()
		$('#consentFlowButton').on('click', function() {
			var url = generateUrl('/apps/sendentsynchroniser/api/1.0/user/activate');
			$('#consentFlowButton').off()
			axios.get(url).then( resp => {
				if (resp.status === 200) {
					if (resp.data.shouldAskMailSync) {
						$('#consentFlowTitle').text(t("sendentsynchroniser", "Step 2: Set up mail"))
						$('#consentFlowText').text(t("sendentsynchroniser", 'Please click the button below to grant permission for accessing your Exchange mailbox.'))
						$('#consentFlowButton').off()
						$('#consentFlowButton').on('click', function() {
							url = generateUrl('/apps/sendentsynchroniser/api/1.0/user/activateMail');
							axios.get(url).then( resp => {
								if (resp.status === 200) {
									configDone()
								} else {
									console.warn('Error while trying to activate IMAP synchronisation', resp)
								}
							})
						})
					} else {
						configDone()
					}
				} else {
					console.warn('Error while trying to activate user', resp)
				}
			})
		})
	})

}
