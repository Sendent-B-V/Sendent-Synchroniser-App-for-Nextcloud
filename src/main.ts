import { createApp } from 'vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import ConsentModal from './components/ConsentModal.vue'

async function init() {
	// Check if we might want to display the sendent synchronisation modal dialog
	const shouldShowUrl = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/shouldShowDialog')
	const shouldShowDialog = await axios.get(shouldShowUrl).then(resp => resp.data)
	if (!shouldShowDialog) {
		return
	}

	// Check if we want to display the sendent synchronisation modal dialog on this particular page
	const methodUrl = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/notificationMethod')
	const notificationMethod = await axios.get(methodUrl).then(resp => resp.data)

	const hasGroupware = document.querySelector('.app-contacts, .app-calendar, .app-tasks')
	const hasFiles = document.getElementById('app-content-files') || document.querySelector('.app-files')

	switch (notificationMethod) {
		case '1':
			if (!hasGroupware) return
			break
		case '2':
			if (!document.getElementById('app-content-files')) return
			break
		case '3':
			if (!hasFiles && !hasGroupware) return
			break
		default:
			return
	}

	console.log('Injecting Sendent Synchronizer modal dialog')

	// Create a mount point and mount the Vue modal
	const mountPoint = document.createElement('div')
	mountPoint.id = 'sendentsynchroniser-modal'

	// Find the best parent element to inject into
	const parent = document.getElementById('app-content-files')
		|| document.getElementById('app-content-vue')
		|| document.getElementById('content')
		|| document.body

	parent.prepend(mountPoint)

	const app = createApp(ConsentModal)
	app.mount(mountPoint)
}

init()
