import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { loadState } from '@nextcloud/initial-state'
import { useGroupsStore } from './stores/groups'
import { useLicenseStore } from './stores/license'
import AdminSettings from './components/AdminSettings.vue'
import UserSettings from './components/UserSettings.vue'

// Admin settings page
const adminEl = document.getElementById('sendentsynchroniser-admin')
if (adminEl) {
	const state = loadState('sendentsynchroniser', 'admin') as Record<string, any>

	const app = createApp(AdminSettings, {
		nbEnabledUsers: state.nbEnabledUsers || 0,
		nbActiveUsers: state.nbActiveUsers || 0,
		sharedSecret: state.sharedSecret || '',
		imapSyncEnabled: state.IMAPSyncEnabled || false,
		emailDomain: state.emailDomain || '',
		reminderType: state.reminderType || '2',
		notificationMethod: state.notificationMethod || '2',
		notificationInterval: state.notificationInterval || '7',
		defaultCalendars: state.defaultCalendars || {},
		defaultAddressbooks: state.defaultAddressbooks || {},
		mailAppInstalled: state.mailAppInstalled || false,
		notificationsAppInstalled: state.notificationsAppInstalled || false,
	})

	const pinia = createPinia()
	app.use(pinia)
	app.mount(adminEl)

	// Initialize stores from initial state
	const groupsStore = useGroupsStore()
	groupsStore.loadInitialState()

	const licenseStore = useLicenseStore()
	licenseStore.refreshStatus()
}

// Personal settings page
const userEl = document.getElementById('sendentsynchroniser-user')
if (userEl) {
	const state = loadState('sendentsynchroniser', 'user') as Record<string, any>

	const app = createApp(UserSettings, {
		activeUser: state.activeUser || false,
	})

	const pinia = createPinia()
	app.use(pinia)
	app.mount(userEl)
}
