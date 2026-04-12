<template>
	<div id="sendentsynchroniser-admin-settings">
		<h2>Sendent Sync</h2>

		<GroupsManagement :nb-enabled-users="nbEnabledUsers"
			:nb-active-users="nbActiveUsers"
			:notifications-app-installed="notificationsAppInstalled" />

		<!-- Content below groups: changes based on selected group -->
		<div v-if="groupsStore.selectedGroupId !== null" class="admin-settings__group-content">
			<h3 class="admin-settings__active-group">
				Editing: {{ groupsStore.selectedGroup?.displayName || '' }}
			</h3>

			<LicenseSection />

			<SettingsSection :initial-shared-secret="sharedSecret"
				:initial-imap-sync-enabled="imapSyncEnabled"
				:initial-email-domain="emailDomain"
				:initial-reminder-type="reminderType"
				:initial-notification-method="notificationMethod"
				:initial-notification-interval="notificationInterval"
				:default-calendars="defaultCalendars"
				:default-addressbooks="defaultAddressbooks"
				:mail-app-installed="mailAppInstalled"
				:notifications-app-installed="notificationsAppInstalled" />
		</div>

		<div v-else class="admin-settings__no-group">
			<p>{{ t('sendentsynchroniser', 'Select an active group above to configure its settings.') }}</p>
		</div>
	</div>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { useGroupsStore } from '../stores/groups'
import GroupsManagement from './GroupsManagement.vue'
import LicenseSection from './LicenseSection.vue'
import SettingsSection from './SettingsSection.vue'

const groupsStore = useGroupsStore()

defineProps<{
	nbEnabledUsers: number
	nbActiveUsers: number
	sharedSecret: string
	imapSyncEnabled: boolean
	emailDomain: string
	reminderType: string | number
	notificationMethod: string | number
	notificationInterval: string | number
	defaultCalendars: Record<string, string>
	defaultAddressbooks: Record<string, string>
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

.admin-settings__active-group {
	font-size: 16px;
	font-weight: 600;
	margin: 0 0 16px 0;
}

.admin-settings__group-content {
	border-top: 1px solid var(--color-border);
	padding-top: 20px;
}

.admin-settings__no-group {
	padding: 20px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>
