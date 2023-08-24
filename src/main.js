/* eslint-disable @nextcloud/no-deprecations */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { activateStartConsentFlowDialog } from './utils/consentFlowScript.js'

$(async () => {

	// Check if we might want to display the sendent synchronisation modal dialog
	var url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/notificationMethod')
	const notificationMethod = await axios.get(url).then( resp => {
		return resp.data
	})
	switch (notificationMethod) {
		case "1":
			if (!$("#app-content-vue").length) {
				return
			}
			break
		case "2":
			if (!$("#app-content-files").length) {
				return
			}
			break
		case "3":
			if (!$("#app-content-files").length & !$("#app-content-vue").length) {
				return
			}
			break
		default:
			return
	}

	console.log('Injecting Sendent Synchronizer modal dialog')

	// Creates modal template
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
	if (notificationMethod === "2") {
		$('#app-content-files').prepend(modal)
	} else {
		$('#app-content-vue').prepend(modal)
	}
	$('#closeSendentSyncModal').on('click', function() {
		$('#sendentSyncModal').hide()
	})

	// Injects startConsentFlow div into modal template
	var url = generateUrl('/apps/sendentsynchroniser/api/1.0/getStartConsentFlowPage')
	axios.get(url).then( resp => {
		const consentFlowDiv = resp.data
		$('#startConsentFlowDiv').prepend(consentFlowDiv)
		activateStartConsentFlowDialog()
	})

	// Shows modal template if needed
	url = generateUrl('/apps/sendentsynchroniser/api/1.0/user/isValid')
	axios.get(url).then( resp => {
		if (!resp.data) {
			$('#sendentSyncModal').show()
		}
	})
})
