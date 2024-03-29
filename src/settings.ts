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
			axios.post(url, {reminderType}).then((resp) => {
				if (resp.status === 200) {
					console.log('Reminder type settting updated successfully')
					$('#enrollmentReminderChangedOk').removeClass("hidden").addClass("shown").delay(1000).queue(function (next) {
						$(this).addClass("hidden");
						$(this).removeClass("shown")
						next();
					});
				} else {
					console.warn('Error while trying to update the Reminder type settting')
					$('#enrollmentReminderChangedKo').removeClass("hidden").addClass("shown").delay(1000).queue(function (next) {
						$(this).addClass("hidden");
						$(this).removeClass("shown")
						next();
					});
				}
			})
		})
		$('#setIMAPSyncEnabled').on('change', function(e) {
			const IMAPSyncEnabled = (<HTMLInputElement>e.target).value
			const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/imapsync');
			// Saves new setting's value
			axios.post(url, {IMAPSyncEnabled}).then((resp) => {
				if (resp.status === 200) {
					$('#IMAPSyncChangedOk').removeClass("hidden").addClass("shown").delay(1000).queue(function (next) {
						$(this).addClass("hidden");
						$(this).removeClass("shown")
						next();
					});
				} else {
					$('#IMAPSyncChangedKo').removeClass("hidden").addClass("shown").delay(1000).queue(function (next) {
						$(this).addClass("hidden");
						$(this).removeClass("shown")
						next();
					});
				}
			})
			// Displays/hides the email domain setting
			if (IMAPSyncEnabled === 'true') {
				$('#emailDomainSetting').removeClass('hidden')
				$('#emailDomainSetting').addClass('shown')
			} else {
				$('#emailDomainSetting').removeClass('shown')
				$('#emailDomainSetting').addClass('hidden')
			}
		})
		$('#setEmailDomain').on('keyup', function(e) {
			clearTimeout($(this).data('timer'))
			$(this).data('timer', setTimeout(function() {
				const emailDomain = (<HTMLInputElement>(<unknown>$('#setEmailDomain')))[0].value
				const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/emailDomain');
				axios.post(url, {emailDomain}).then((resp) => {
					if (resp.status === 200) {
						$('#emailDomainChangedOk').removeClass("hidden").addClass("shown").delay(1000).queue(function (next) {
							$(this).addClass("hidden");
							$(this).removeClass("shown")
							next();
						});
					} else {
						$('#emailDomainChangedOk').removeClass("hidden").addClass("shown").delay(1000).queue(function (next) {
							$(this).addClass("hidden");
							$(this).removeClass("shown")
							next();
						});
					}
				})
			},500))
		})

		$('#setNotificationMethod').on('change', function(e) {
			const notificationMethod = (<HTMLInputElement>e.target).value
			const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/notificationMethod');
			axios.post(url, {notificationMethod}).then((resp) => {
				if (resp.status === 200) {
					$('#ModalNotificationChangedOk').removeClass("hidden").addClass("shown").delay(1000).queue(function (next) {
						$(this).addClass("hidden");
						$(this).removeClass("shown")
						next();
					});
				} else {
					$('#ModalNotificationChangedKo').removeClass("hidden").addClass("shown").delay(1000).queue(function (next) {
						$(this).addClass("hidden");
						$(this).removeClass("shown")
						next();
					});
				}
			})
		})
		$('#setNotificationInterval').on('change', function(e) {
			const notificationInterval = (<HTMLInputElement>e.target).value
			const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/notificationInterval');
			axios.post(url, {notificationInterval}).then((resp) => {
				if (resp.status === 200) {
					$('#NotificationsIntervalChangedOk').removeClass("hidden").addClass("shown").delay(1000).queue(function (next) {
						$(this).addClass("hidden");
						$(this).removeClass("shown")
						next();
					});
				} else {				
					$('#NotificationsIntervalChangedKo').removeClass("hidden").addClass("shown").delay(1000).queue(function (next) {
						$(this).addClass("hidden");
						$(this).removeClass("shown")
						next();
					});
				}
			})
		})
		$('#setSharedSecret').on('keyup', function(e) {
			clearTimeout($(this).data('timer'))
			$(this).data('timer', setTimeout(function() {
				const sharedSecret = (<HTMLInputElement>(<unknown>$('#setSharedSecret')))[0].value
				if (sharedSecret !== '') {
					const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/sharedSecret');
					axios.post(url, {sharedSecret}).then((resp) => {
						if (resp.status === 200) {
							$('#sharedSecretChangedOk').removeClass("hidden").addClass("shown").delay(1000).queue(function (next) {
								$(this).addClass("hidden");
								$(this).removeClass("shown")
								next();
							});
						} else {
							$('#sharedSecretChangedKo').removeClass("hidden").addClass("shown").delay(1000).queue(function (next) {
								$(this).addClass("hidden");
								$(this).removeClass("shown")
								next();
							});
						}
					})
				}
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
			axios.get(url).then(() => {
				// TODO handle error
				location.reload()
			})
		})
	}
})
