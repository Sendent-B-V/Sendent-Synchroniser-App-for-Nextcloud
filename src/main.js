/* eslint-disable @nextcloud/no-deprecations */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { activateConsentFlowDialog } from './utils/consentFlowScript.js'

$(async () => {

	// Check if licensing is OK
	var url = generateUrl('/apps/sendentsynchroniser/api/1.0/licensestatus')
	const licenseStatus = await axios.get(url).then( resp => {
		return resp.data
	})
	/*  TODO: Activate
	if (licenseStatus['statusKind'] !== 'valid') {
		console.log('No valid Sendent synchroniser license')
		return
	}
	*/

	// Check if we might want to display the sendent synchronisation modal dialog
	var url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/notificationMethod')
	const notificationMethod = await axios.get(url).then( resp => {
		return resp.data
	})
	switch (notificationMethod) {
		case "1":
			// We want to display the modal dialog in Calendar, Contacts, and Tasks.
			if (!$(".contact-header").length & !$(".appointment-config-list").length & !$(".task-list").length) {
				return
			}
			break
		case "2":
			// We want to display the modal dialog in the Files app only 
			if (!$("#app-content-files").length) {
				return
			}
			break
		case "3":
			// We want to display in both the Files app, and the groupware apps
			if (!$("#app-content-files").length & !$(".contact-header").length & !$(".appointment-config-list").length & !$(".task-list").length) {
				return
			}
			break
		default:
			return
	}

	console.log('Injecting Sendent Synchronizer modal dialog')

	// Creates modal template
	// TODO: Style should be loaded via regular CSS file
	const modal = '<div id="sendentSyncModal" style="display:none;position:fixed;inset:0px;z-index:10000;background: rgba(0,0,0,0.6)" aria-hidden="true">' +
		'<div style="position:fixed;left:50%;top:50%;z-index:11000;width:700px;text-align:center;background:#fefefe;border:#333333 solid 0px;border-radius:5px;margin-left:-200px">' +
			'<div style="padding:10px 20px">' +
				'<h2>Sendent synchronisation not active</h2>' +
				'<a href="#" id="closeSendentSyncModal" style="color:#aaaaa;font-size:20px;text-decoration:none;padding:10px;position:absolute;right:7px;top:0;" aria-hidden="true">&times;</a>' +
			'</div>' +
			'<div id="startConsentFlowDiv" style="padding:20px;"/>' +
		'</div>' +
	'</div>'

	// Injects modal template
	switch (notificationMethod) {
		case "1":
			// We want to inject in Calendar, Contacts, and Tasks.
			$('#app-content-vue').prepend(modal)
			break
		case "2":
			// We want to inject in the Files app only
			$('#app-content-files').prepend(modal)
			break
		case "3":
			// We want to inject in both the Files app, and the groupware apps
			if ($('#app-content-files').length) {
				$('#app-content-files').prepend(modal)
			} else {
				$('#app-content-vue').prepend(modal)
			}
	}
	$('#closeSendentSyncModal').on('click', function() {
		$('#sendentSyncModal').hide()
	})

	// Injects startConsentFlow div into modal template
	var url = generateUrl('/apps/sendentsynchroniser/api/1.0/getConsentFlowPage')
	axios.get(url).then( resp => {
		const consentFlowDiv = resp.data
		$('#startConsentFlowDiv').prepend(consentFlowDiv)
		// TODO: Style should be loaded via regular CSS file
		$('#consentFlowTitle').css({"font-size" : "16px", "font-weight" : "bold", "margin-bottom": "20px"})
		activateConsentFlowDialog()
	})

	// Shows modal template if needed
	url = generateUrl('/apps/sendentsynchroniser/api/1.0/user/isValid')
	axios.get(url).then( resp => {
		if (!resp.data) {
			$('#sendentSyncModal').show()
		}
	})
})
