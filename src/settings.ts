/* eslint-disable @nextcloud/no-deprecations */
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import GroupsManagementHandler from "./imports/GroupsManagementHandler";

$(() => {
    console.log('Sendentsynchroniser settings script loaded')

	if ($("#groupsManagement").length) {
		// Admin settings page
		GroupsManagementHandler.setup()
		$('#setNotificationMethod').on('change', function(e) {
			console.log('Changing notification method')
			const notificationMethod = (<HTMLInputElement>e.target).value
			const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/notificationMethod');
			axios.post(url, {notificationMethod})
		})
		$('#setSharedSecret').on('keyup', function(e) {
			clearTimeout($(this).data('timer'))
			$(this).data('timer', setTimeout(function() {
				const sharedSecret = (<HTMLInputElement>(<unknown>$('#setSharedSecret')))[0].value
				console.log('Changing shared secret')
				const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/sharedSecret');
				axios.post(url, {sharedSecret})
			},500))
		})
	} else {
		// Personal settings page
		$('#startConsentFlowButton').on('click', function(e) {
			console.log('Starting consent flow')
			$('#startConsentFlowText').hide()
			$('#startConsentFlowButton').hide()
			$('#giveAccessCalendarText').show()
			$('#giveAccessCalendarButton').show()
		})

		$('#giveAccessCalendarButton').on('click', function(e) {
			console.log('Creating an app token to give synchroniser app access to nextcloud user data')
			const url = generateUrl('/apps/sendentsynchroniser/api/1.0/permit');
			axios.get(url).then( resp => {
				if (resp) {
					console.log(resp)
				} else {
					console.log('not ' + resp)
				}
			})
		})
	}
})
