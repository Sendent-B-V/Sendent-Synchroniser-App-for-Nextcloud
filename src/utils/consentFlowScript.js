import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export function activateStartConsentFlowDialog() {
	$('#startConsentFlowButton').on('click', function(e) {
		console.log('Starting consent flow')
		$('#startConsentFlowText').hide()
		$('#startConsentFlowButton').hide()
		$('#giveAccessCalendarText').show()
		$('#giveAccessCalendarButton').show()
	})

	$('#giveAccessCalendarButton').on('click', function(e) {
		console.log('Creating an app token to give synchroniser app access to nextcloud user data')
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/user/activate');
		axios.get(url).then( resp => {
			if (resp) {
				console.log(resp)
			} else {
				console.log('not ' + resp)
			}
		})
	})
}
