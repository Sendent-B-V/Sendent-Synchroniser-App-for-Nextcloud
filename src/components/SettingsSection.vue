<template>
	<div class="settings-section">
		<h3>{{ t('sendentsynchroniser', 'Settings') }}</h3>
		<p class="settings-section__subtitle">Changes are saved automatically</p>

		<!-- Shared secret -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Shared secret') }}</label>
			<div class="settings-section__input-row">
				<input :type="showSecret ? 'text' : 'password'"
					v-model="sharedSecret"
					class="settings-section__input"
					@keyup="debounceSaveSecret">
				<button class="settings-section__toggle"
					@click.prevent="showSecret = !showSecret">
					<img :src="viewIconUrl" style="height:20px;width:20px" />
				</button>
				<span v-if="saved.sharedSecret" class="settings-section__saved">&#x2713;</span>
			</div>
		</div>

		<!-- IMAP Sync -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Enable IMAP synchronisation') }}</label>
			<div class="settings-section__input-row">
				<select v-model="imapSyncEnabled"
					class="settings-section__input"
					:disabled="!mailAppInstalled"
					@change="saveIMAPSync">
					<option value="true">{{ t('sendentsynchroniser', 'Enabled') }}</option>
					<option value="false">{{ t('sendentsynchroniser', 'Disabled') }}</option>
				</select>
				<span v-if="!mailAppInstalled" class="settings-section__warning">
					{{ t('sendentsynchroniser', "You don't have the mail app installed") }}
				</span>
				<span v-if="saved.imapSync" class="settings-section__saved">&#x2713;</span>
			</div>
		</div>

		<!-- Email domain -->
		<div v-if="imapSyncEnabled === 'true'" class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Email domain') }}</label>
			<div class="settings-section__input-row">
				<input v-model="emailDomain"
					class="settings-section__input"
					placeholder="acme.com"
					@keyup="debounceSaveEmailDomain">
				<span v-if="saved.emailDomain" class="settings-section__saved">&#x2713;</span>
			</div>
		</div>

		<h3>{{ t('sendentsynchroniser', 'Default collections') }}</h3>
		<p class="settings-section__subtitle">{{ t('sendentsynchroniser', 'When set, these collections are automatically created for users during activation. Leave empty to use Nextcloud defaults (Personal / Contacts).') }}</p>

		<!-- Default calendar -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Default calendar URI') }}</label>
			<div class="settings-section__input-row">
				<input v-model="defaultCalendar"
					class="settings-section__input"
					placeholder="e.g. exchange"
					@keyup="debounceSaveDefaultCalendar">
				<span v-if="saved.defaultCalendar" class="settings-section__saved">&#x2713;</span>
			</div>
		</div>

		<!-- Default addressbook -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Default addressbook URI') }}</label>
			<div class="settings-section__input-row">
				<input v-model="defaultAddressbook"
					class="settings-section__input"
					placeholder="e.g. exchange-contacts"
					@keyup="debounceSaveDefaultAddressbook">
				<span v-if="saved.defaultAddressbook" class="settings-section__saved">&#x2713;</span>
			</div>
		</div>

		<h3>{{ t('sendentsynchroniser', 'Enrollment reminders') }}</h3>

		<!-- Reminder type -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Reminder type') }}</label>
			<div class="settings-section__input-row">
				<select v-model="reminderType"
					class="settings-section__input"
					@change="saveReminderType">
					<option value="1">Modal dialog</option>
					<option value="2" :disabled="!notificationsAppInstalled">Standard notifications</option>
					<option value="3" :disabled="!notificationsAppInstalled">Modal dialog and standard notifications</option>
				</select>
				<span v-if="!notificationsAppInstalled" class="settings-section__warning">
					{{ t('sendentsynchroniser', "You don't have the notifications app installed") }}
				</span>
				<span v-if="saved.reminderType" class="settings-section__saved">&#x2713;</span>
			</div>
		</div>

		<!-- Notification method -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Applications to show modal dialog on') }}</label>
			<div class="settings-section__input-row">
				<select v-model="notificationMethod"
					class="settings-section__input"
					@change="saveNotificationMethod">
					<option value="1">Show in Mail, Calendar, Contacts, and Tasks</option>
					<option value="2">Show in Files</option>
					<option value="3">Show everywhere (options 1 and 2 combined)</option>
				</select>
				<span v-if="saved.notificationMethod" class="settings-section__saved">&#x2713;</span>
			</div>
		</div>

		<!-- Notification interval -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Notifications interval in days') }}</label>
			<div class="settings-section__input-row">
				<input v-model="notificationInterval"
					class="settings-section__input"
					:disabled="!notificationsAppInstalled"
					@change="saveNotificationInterval">
				<span v-if="!notificationsAppInstalled" class="settings-section__warning">
					{{ t('sendentsynchroniser', "You don't have the notifications app installed") }}
				</span>
				<span v-if="saved.notificationInterval" class="settings-section__saved">&#x2713;</span>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl, imagePath } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { useGroupsStore } from '../stores/groups'

const groupsStore = useGroupsStore()

const props = defineProps<{
	initialSharedSecret: string
	initialImapSyncEnabled: boolean
	initialEmailDomain: string
	initialReminderType: string | number
	initialNotificationMethod: string | number
	initialNotificationInterval: string | number
	defaultCalendars: Record<string, string>
	defaultAddressbooks: Record<string, string>
	mailAppInstalled: boolean
	notificationsAppInstalled: boolean
}>()

const sharedSecret = ref(props.initialSharedSecret)
const imapSyncEnabled = ref(props.initialImapSyncEnabled ? 'true' : 'false')
const emailDomain = ref(props.initialEmailDomain)
const reminderType = ref(String(props.initialReminderType))
const notificationMethod = ref(String(props.initialNotificationMethod))
const notificationInterval = ref(String(props.initialNotificationInterval))

// Per-group defaults — read from maps using selected group
const calendarMaps = ref<Record<string, string>>({ ...props.defaultCalendars })
const addressbookMaps = ref<Record<string, string>>({ ...props.defaultAddressbooks })

const defaultCalendar = computed({
	get: () => (groupsStore.selectedGroupId ? calendarMaps.value[groupsStore.selectedGroupId] : '') || '',
	set: (val: string) => {
		if (groupsStore.selectedGroupId) {
			calendarMaps.value = { ...calendarMaps.value, [groupsStore.selectedGroupId]: val }
		}
	},
})
const defaultAddressbook = computed({
	get: () => (groupsStore.selectedGroupId ? addressbookMaps.value[groupsStore.selectedGroupId] : '') || '',
	set: (val: string) => {
		if (groupsStore.selectedGroupId) {
			addressbookMaps.value = { ...addressbookMaps.value, [groupsStore.selectedGroupId]: val }
		}
	},
})
const showSecret = ref(false)
const viewIconUrl = imagePath('sendentsynchroniser', 'view.svg')

const saved = reactive<Record<string, boolean>>({})

function showSaved(key: string) {
	saved[key] = true
	setTimeout(() => { saved[key] = false }, 1500)
}

async function saveSetting(endpoint: string, data: Record<string, string>, feedbackKey: string) {
	const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/' + endpoint)
	try {
		await axios.post(url, data)
		showSaved(feedbackKey)
	} catch {
		console.error('Failed to save setting:', endpoint)
	}
}

let secretTimer: ReturnType<typeof setTimeout>
function debounceSaveSecret() {
	clearTimeout(secretTimer)
	secretTimer = setTimeout(() => {
		if (sharedSecret.value !== '') {
			saveSetting('sharedSecret', { sharedSecret: sharedSecret.value }, 'sharedSecret')
		}
	}, 500)
}

let emailDomainTimer: ReturnType<typeof setTimeout>
function debounceSaveEmailDomain() {
	clearTimeout(emailDomainTimer)
	emailDomainTimer = setTimeout(() => {
		saveSetting('emailDomain', { emailDomain: emailDomain.value }, 'emailDomain')
	}, 500)
}

function saveIMAPSync() { saveSetting('imapsync', { IMAPSyncEnabled: imapSyncEnabled.value }, 'imapSync') }
function saveReminderType() { saveSetting('reminderType', { reminderType: reminderType.value }, 'reminderType') }
function saveNotificationMethod() { saveSetting('notificationMethod', { notificationMethod: notificationMethod.value }, 'notificationMethod') }
function saveNotificationInterval() { saveSetting('notificationInterval', { notificationInterval: notificationInterval.value }, 'notificationInterval') }

let defaultCalendarTimer: ReturnType<typeof setTimeout>
function debounceSaveDefaultCalendar() {
	clearTimeout(defaultCalendarTimer)
	defaultCalendarTimer = setTimeout(() => {
		if (groupsStore.selectedGroupId) {
			saveSetting('defaultCalendar', {
				defaultCalendar: defaultCalendar.value,
				groupId: groupsStore.selectedGroupId,
			}, 'defaultCalendar')
		}
	}, 500)
}

let defaultAddressbookTimer: ReturnType<typeof setTimeout>
function debounceSaveDefaultAddressbook() {
	clearTimeout(defaultAddressbookTimer)
	defaultAddressbookTimer = setTimeout(() => {
		if (groupsStore.selectedGroupId) {
			saveSetting('defaultAddressbook', {
				defaultAddressbook: defaultAddressbook.value,
				groupId: groupsStore.selectedGroupId,
			}, 'defaultAddressbook')
		}
	}, 500)
}
</script>

<style scoped>
.settings-section {
	margin-bottom: 24px;
}

.settings-section h3 {
	font-size: 16px;
	font-weight: 600;
	margin: 16px 0 8px 0;
}

.settings-section h3:first-child {
	margin-top: 0;
}

.settings-section__subtitle {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	margin-bottom: 16px;
}

.settings-section__field {
	margin-bottom: 12px;
}

.settings-section__field label {
	display: block;
	font-weight: 500;
	margin-bottom: 4px;
}

.settings-section__input-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.settings-section__input {
	width: 100%;
	max-width: 400px;
}

.settings-section__toggle {
	background: none;
	border: none;
	cursor: pointer;
	padding: 4px;
}

.settings-section__warning {
	color: var(--color-error-text);
	font-style: italic;
	font-size: 13px;
}

.settings-section__saved {
	color: var(--color-success-text);
	font-weight: 600;
	animation: fadeIn 0.3s;
}

@keyframes fadeIn {
	from { opacity: 0; }
	to { opacity: 1; }
}
</style>
