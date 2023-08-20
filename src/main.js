/* eslint-disable @nextcloud/no-deprecations */
import axios from '@nextcloud/axios';
import { generateUrl } from '@nextcloud/router';

$(() => {

	// Do not inject modal if we are not in an app page
	if ( !$( "#app-content-vue" ).length ) {
		return
	}

	console.log('Injecting Sendent Synchronizer modal dialog')

	var modal = '<div id="sendentSyncModal" style="display:none;position:fixed;inset:0px;z-index:10000;background: rgba(0,0,0,0.6)" aria-hidden="true">' +
		'<div style="position:fixed;left:50%;top:50%;z-index:11000;width:400px;text-align:center;background:#fefefe;border:#333333 solid 0px;border-radius:5px;margin-left:-200px">' +
			'<div style="padding:10px 20px">' +
				'<h2>Sendent synchronisation not active</h2>' +
				'<a href="#" id="closeSendentSyncModal" style="color:#aaaaa;font-size:20px;text-decoration:none;padding:10px;position:absolute;right:7px;top:0;" aria-hidden="true">&times;</a>' +
			'</div>' +
			'<div style="padding:20px;">' +
				'<input id="setupNavigationButton" type="button" value="Setup synchronisation"/>' +
			'</div>' +
		'</div>' +
	'</div>'

	$('#app-content-vue').prepend(modal)
	$('#closeSendentSyncModal').on('click', function() {
		$('#sendentSyncModal').hide()
	})
	$('#setupNavigationButton').on('click', function() {
		const url = generateUrl('/settings/user/sendentsynchroniser')
		window.location.href = url;
	})

	const url = generateUrl('/apps/sendentsynchroniser/api/1.0/user/isValid')
	axios.get(url).then( resp => {
		if (!resp.data) {
			$('#sendentSyncModal').show()
		}
	})
})
