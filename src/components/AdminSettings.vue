<template>
	<div id="sendentsynchroniser-admin-settings">
		<h2>Sendent Sync</h2>

		<GroupsManagement :nb-enabled-users="nbEnabledUsers"
			:nb-active-users="nbActiveUsers"
			:notifications-app-installed="notificationsAppInstalled" />

		<LicenseSection />

		<SettingsSection :initial-shared-secret="sharedSecret"
			:initial-imap-sync-enabled="imapSyncEnabled"
			:initial-email-domain="emailDomain"
			:initial-reminder-type="reminderType"
			:initial-notification-method="notificationMethod"
			:initial-notification-interval="notificationInterval"
			:initial-default-calendar="defaultCalendar"
			:initial-default-addressbook="defaultAddressbook"
			:initial-graph-api-mode="graphApiMode"
			:mail-app-installed="mailAppInstalled"
			:notifications-app-installed="notificationsAppInstalled" />
	</div>
</template>

<script setup lang="ts">
import GroupsManagement from './GroupsManagement.vue'
import LicenseSection from './LicenseSection.vue'
import SettingsSection from './SettingsSection.vue'

defineProps<{
	nbEnabledUsers: number
	nbActiveUsers: number
	sharedSecret: string
	imapSyncEnabled: boolean
	emailDomain: string
	reminderType: string | number
	notificationMethod: string | number
	notificationInterval: string | number
	defaultCalendar: string
	defaultAddressbook: string
	graphApiMode: boolean
	mailAppInstalled: boolean
	notificationsAppInstalled: boolean
}>()
</script>

<style scoped>
#sendentsynchroniser-admin-settings {
	padding: 20px;
	max-width: 1200px;
}

#sendentsynchroniser-admin-settings h2 {
	font-size: 20px;
	font-weight: 700;
	margin-bottom: 16px;
}
</style>
