/* eslint-disable @nextcloud/no-deprecations */
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';
import GroupsManagementHandler from "./imports/GroupsManagementHandler";
import LicenseHandler from "./imports/LicenseHandler";
import { activateConsentFlowDialog } from './utils/consentFlowScript.js'

$(() => {
    console.log('Sendentsynchroniser settings script loaded')

	if ($("#groupsManagement").length) {
		// Admin settings page
		LicenseHandler.setup();
		GroupsManagementHandler.setup()
		$('#setNotificationMethod').on('change', function(e) {
			console.log('Changing notification method')
			const notificationMethod = (<HTMLInputElement>e.target).value
			const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/notificationMethod');
			axios.post(url, {notificationMethod})
			// TODO provide feedback to admin
		})
		$('#setSharedSecret').on('keyup', function(e) {
			clearTimeout($(this).data('timer'))
			$(this).data('timer', setTimeout(function() {
				const sharedSecret = (<HTMLInputElement>(<unknown>$('#setSharedSecret')))[0].value
				console.log('Changing shared secret')
				const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/sharedSecret');
				axios.post(url, {sharedSecret})
				// TODO provide feedback to admin
			},500))
		})
		$('#showSharedSecret').on('mousedown', function(e) {
			(<HTMLInputElement>(<unknown>$('#setSharedSecret')))[0].type = "text"
		 }).on('mouseup', function(e) {
			(<HTMLInputElement>(<unknown>$('#setSharedSecret')))[0].type = "password"
		 });
		 $('#showSharedSecret').on('click', function(e) {
			e.preventDefault()
		 })
	} else {
		// Personal settings page
		activateConsentFlowDialog()
		$('#retractConsentButton').on('click', function(e) {
			const url = generateUrl('/apps/sendentsynchroniser/api/1.0/user/invalidate');
			axios.get(url)
			// TODO: should reload the page or show a feedback to the user
		})
	}
})
