<template>
	<div id="sendentsynchroniser-admin-settings">
		<h2>Synchronizer</h2>

		<nav class="admin-tabs">
			<button type="button"
				:class="['admin-tabs__tab', { 'admin-tabs__tab--active': tab === 'general' }]"
				@click="tab = 'general'">
				{{ t('sendentsynchroniser', 'General') }}
			</button>
			<button type="button"
				:class="['admin-tabs__tab', { 'admin-tabs__tab--active': tab === 'rooms' }]"
				@click="tab = 'rooms'">
				{{ t('sendentsynchroniser', 'Rooms Management') }}
			</button>
			<button type="button"
				:class="['admin-tabs__tab', { 'admin-tabs__tab--active': tab === 'sync' }]"
				@click="tab = 'sync'">
				{{ t('sendentsynchroniser', 'Synchronization Management') }}
			</button>
		</nav>

		<!-- Tab 1: General — read-only summary cards.
			 License *management* (activate/clear) lives on Tab 3 only. -->
		<section v-if="tab === 'general'" class="admin-tab-panel">
			<div class="admin-overview">
				<div class="admin-overview__card">
					<div class="admin-overview__label">
						{{ t('sendentsynchroniser', 'Users enabled for sync') }}
					</div>
					<div class="admin-overview__value">
						{{ nbEnabledUsers }}
					</div>
				</div>
				<div class="admin-overview__card">
					<div class="admin-overview__label">
						{{ t('sendentsynchroniser', 'Users actively syncing') }}
					</div>
					<div class="admin-overview__value">
						{{ nbActiveUsers }}
					</div>
				</div>
				<div class="admin-overview__card">
					<div class="admin-overview__label">
						{{ t('sendentsynchroniser', 'Rooms') }}
					</div>
					<div class="admin-overview__value">
						{{ roomsStore.rooms.length }}
					</div>
					<div v-if="boundRoomsCount > 0" class="admin-overview__sub">
						{{ t('sendentsynchroniser', '{n} bound to Exchange', { n: String(boundRoomsCount) }) }}
					</div>
				</div>
				<div class="admin-overview__card">
					<div class="admin-overview__label">
						{{ t('sendentsynchroniser', 'License') }}
					</div>
					<div class="admin-overview__value admin-overview__value--small"
						:class="`admin-overview__license--${licenseStatusKind}`">
						{{ licenseStatusLabel }}
					</div>
					<div v-if="licenseExpiration"
						class="admin-overview__sub">
						{{ t('sendentsynchroniser', 'Expires:') }} {{ formatDate(licenseExpiration) }}
					</div>
					<div class="admin-overview__sub">
						<a href="#" @click.prevent="tab = 'sync'">
							{{ t('sendentsynchroniser', 'Manage license →') }}
						</a>
					</div>
				</div>
			</div>
		</section>

		<!-- Tab 2: Rooms Management -->
		<section v-else-if="tab === 'rooms'" class="admin-tab-panel">
			<RoomsManagement />
		</section>

		<!-- Tab 3: Synchronization Management — groups → license → user (Connector) settings -->
		<section v-else-if="tab === 'sync'" class="admin-tab-panel">
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
		</section>
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import GroupsManagement from './GroupsManagement.vue'
import LicenseSection from './LicenseSection.vue'
import SettingsSection from './SettingsSection.vue'
import RoomsManagement from './RoomsManagement.vue'
import { useRoomsStore } from '../stores/rooms'
import { useLicenseStore } from '../stores/license'

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

const tab = ref<'general' | 'rooms' | 'sync'>('general')

const roomsStore = useRoomsStore()
const licenseStore = useLicenseStore()

const boundRoomsCount = computed(
	() => roomsStore.rooms.filter(r => r.binding !== null && r.binding !== undefined).length,
)

const licenseStatusKind = computed<string>(() => licenseStore.status?.statusKind ?? 'nolicense')
const licenseExpiration = computed<string>(() => licenseStore.status?.dateExpiration ?? '')
const licenseStatusLabel = computed<string>(() => {
	switch (licenseStatusKind.value) {
	case 'valid': return t('sendentsynchroniser', 'Valid')
	case 'expired': return t('sendentsynchroniser', 'Expired')
	case 'nolicense': return t('sendentsynchroniser', 'No license')
	case 'userlimit': return t('sendentsynchroniser', 'User limit reached')
	case 'check': return t('sendentsynchroniser', 'Check required')
	case 'error_incomplete': return t('sendentsynchroniser', 'Incomplete')
	default: return licenseStatusKind.value
	}
})

/**
 *
 * @param iso
 */
function formatDate(iso: string): string {
	if (!iso) return ''
	try { return new Date(iso).toLocaleDateString() } catch { return iso }
}

onMounted(() => {
	// Refresh rooms once on mount so the General tab can show an accurate count
	// even if the user never opens the Rooms tab.
	roomsStore.refresh()
	// License status is loaded by settings.ts on initial mount; refresh here is
	// a defensive no-op if the store is already populated.
	if (licenseStore.status === null) {
		licenseStore.refreshStatus()
	}
})
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

.admin-tabs {
	display: flex !important;
	gap: 4px;
	border-bottom: 1px solid var(--color-border, #ccc);
	margin-bottom: 24px;
	flex-wrap: wrap;
}
.admin-tabs__tab {
	background: none !important;
	border: none !important;
	padding: 10px 18px !important;
	cursor: pointer;
	border-bottom: 2px solid transparent !important;
	border-radius: 0 !important;
	font-size: 14px;
	color: var(--color-text-maxcontrast, #555);
	margin: 0 !important;
}
.admin-tabs__tab:hover {
	background: var(--color-background-hover, #f6f6f6) !important;
}
.admin-tabs__tab--active {
	border-bottom-color: var(--color-primary, #1976d2) !important;
	color: var(--color-main-text, #000);
	font-weight: 600;
}

.admin-tab-panel {
	display: block;
}

.admin-overview {
	display: grid !important;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 16px;
	margin-bottom: 24px;
}
.admin-overview__card {
	border: 1px solid var(--color-border, #ccc);
	border-radius: 6px;
	padding: 16px;
	background: var(--color-background-hover, #fafafa);
}
.admin-overview__label {
	font-size: 13px;
	color: var(--color-text-maxcontrast, #555);
	margin-bottom: 6px;
}
.admin-overview__value {
	font-size: 28px;
	font-weight: 600;
	line-height: 1;
}
.admin-overview__value--small {
	font-size: 18px;
	text-transform: capitalize;
}
.admin-overview__sub {
	font-size: 12px;
	color: var(--color-text-maxcontrast, #777);
	margin-top: 6px;
}
.admin-overview__sub a { color: var(--color-primary, #1976d2); text-decoration: none; }
.admin-overview__sub a:hover { text-decoration: underline; }
.admin-overview__license--valid { color: #2e7d32; }
.admin-overview__license--expired { color: #c62828; }
.admin-overview__license--nolicense { color: var(--color-text-maxcontrast, #777); }
.admin-overview__license--userlimit { color: #f57c00; }
.admin-overview__license--check { color: #c62828; }
.admin-overview__license--error_incomplete { color: #c62828; }
</style>
