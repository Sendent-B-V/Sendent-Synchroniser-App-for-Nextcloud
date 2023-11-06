import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t } from '@nextcloud/l10n'

export function activateConsentFlowDialog() {
	$('#consentFlowButton').on('click', function(e) {
		console.log('Starting consent flow')
		$('#consentFlowButton').val(t("sendentsynchroniser", "Give access"))
		$('#consentFlowHeader').val("");
		$('#consentFlowTitle').text(t("sendentsynchroniser", "Step 1: Set up appointments, contacts, and tasks"))
		$('#consentFlowText').text(t("sendentsynchroniser", 'Please click the button below to sync your Outlook appointments, contacts, and tasks with Nextcloud.'))
		$('#consentFlowButton').off()
		$('#consentFlowButton').on('click', function(e) {
			console.log('Creating an app token to give synchroniser app access to nextcloud user data')
			var url = generateUrl('/apps/sendentsynchroniser/api/1.0/user/activate');
			$('#consentFlowButton').off()
			axios.get(url).then( resp => {
				$('#consentFlowTitle').text(t("sendentsynchroniser", "Step 2: Set up mail"))
				$('#consentFlowText').text(t("sendentsynchroniser", 'Please click the button below to grant permission for accessing your Exchange mailbox.'))
				$('#consentFlowButton').off()
				$('#consentFlowButton').on('click', function(e) {
					console.log('Granting access to user Exchange mailbox')
					url = generateUrl('/apps/sendentsynchroniser/api/1.0/user/activateMail');
					axios.get(url).then( resp => {
						$('#consentFlowButton').hide()
						$('#consentFlowTitle').text(t("sendentsynchroniser", "Configuration complete"))
						$('#consentFlowText').text(t("sendentsynchroniser", 'Your account is fully configured for Exchange synchronization.'))
						setTimeout(() => {location.reload(),8000})
					})
				})
			})
		})
	})

}
