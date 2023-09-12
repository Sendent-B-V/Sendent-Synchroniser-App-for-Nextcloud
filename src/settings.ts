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
		$('#setReminderType').on('change', function(e) {
			const reminderType = (<HTMLInputElement>e.target).value
			const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/reminderType');
			axios.post(url, {reminderType}).then(() => {
				$('#enrollmentReminderChangedOk').removeClass("hidden").addClass("shown").delay(1000).queue(function (next) {
					$(this).addClass("hidden");
					$(this).removeClass("shown")
					next();
				});
			})
			// TODO provide feedback to admin
		})
		$('#setNotificationMethod').on('change', function(e) {
			const notificationMethod = (<HTMLInputElement>e.target).value
			const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/notificationMethod');
			axios.post(url, {notificationMethod}).then(() => {
				$('#ModalNotificationChangedOk').removeClass("hidden").addClass("shown").delay(1000).queue(function (next) {
					$(this).addClass("hidden");
					$(this).removeClass("shown")
					next();
				});
			})
			// TODO provide feedback to admin
		})
		$('#setNotificationInterval').on('change', function(e) {
			const notificationInterval = (<HTMLInputElement>e.target).value
			const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/notificationInterval');
			axios.post(url, {notificationInterval}).then(() => {
				$('#NotificationsIntervalChangedOk').removeClass("hidden").addClass("shown").delay(1000).queue(function (next) {
					$(this).addClass("hidden");
					$(this).removeClass("shown")
					next();
				});
			})
			// TODO provide feedback to admin
		})
		$('#setSharedSecret').on('keyup', function(e) {
			clearTimeout($(this).data('timer'))
			$(this).data('timer', setTimeout(function() {
				const sharedSecret = (<HTMLInputElement>(<unknown>$('#setSharedSecret')))[0].value
				const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/sharedSecret');
				axios.post(url, {sharedSecret}).then(() => {
					$('#sharedSecretChangedOk').removeClass("hidden").addClass("shown").delay(1000).queue(function (next) {
						$(this).addClass("hidden");
						$(this).removeClass("shown")
						next();
					});
				})
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
