<template>
	<div id="sendentsynchroniser-user-settings">
		<h2>Sendent Sync</h2>
		<ConsentFlow :active-user="isActive"
			@consent-changed="isActive = true" />

		<!-- Post-setup collection change for active users -->
		<template v-if="isActive">
			<div class="user-settings__collections">
				<h3>{{ t('sendentsynchroniser', 'Sync targets') }}</h3>
				<p class="user-settings__subtitle">{{ t('sendentsynchroniser', 'Choose which calendar and addressbook receive your Exchange data.') }}</p>

				<CollectionSelector
					ref="calSelector"
					:label="t('sendentsynchroniser', 'Target calendar')"
					:collections="calendars"
					v-model="selectedCalendar"
					:create-placeholder="t('sendentsynchroniser', 'New calendar name')"
					@create="onCreateCalendar" />

				<CollectionSelector
					ref="abSelector"
					:label="t('sendentsynchroniser', 'Target addressbook')"
					:collections="addressbooks"
					v-model="selectedAddressbook"
					:create-placeholder="t('sendentsynchroniser', 'New addressbook name')"
					@create="onCreateAddressbook" />

				<div class="user-settings__actions">
					<button class="primary" @click="saveCollections">
						{{ t('sendentsynchroniser', 'Save') }}
					</button>
					<span v-if="saveSuccess" class="user-settings__saved">&#x2713; {{ t('sendentsynchroniser', 'Saved') }}</span>
				</div>
			</div>

			<RetractConsent />
		</template>
	</div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import ConsentFlow from './ConsentFlow.vue'
import RetractConsent from './RetractConsent.vue'
import CollectionSelector from './CollectionSelector.vue'

interface CollectionItem {
	id: number
	uri: string
	displayName: string
}

const props = defineProps<{
	activeUser: boolean
}>()

const isActive = ref(props.activeUser)
const calendars = ref<CollectionItem[]>([])
const addressbooks = ref<CollectionItem[]>([])
const selectedCalendar = ref('')
const selectedAddressbook = ref('')
const saveSuccess = ref(false)

async function loadCollections() {
	if (!isActive.value) return

	try {
		const [calResp, abResp] = await Promise.all([
			axios.get(generateUrl('/apps/sendentsynchroniser/api/1.0/user/calendars')),
			axios.get(generateUrl('/apps/sendentsynchroniser/api/1.0/user/addressbooks')),
		])
		calendars.value = calResp.data || []
		addressbooks.value = abResp.data || []

		// Pre-select first if nothing is set
		if (calendars.value.length > 0 && !selectedCalendar.value) {
			selectedCalendar.value = calendars.value[0].uri
		}
		if (addressbooks.value.length > 0 && !selectedAddressbook.value) {
			selectedAddressbook.value = addressbooks.value[0].uri
		}
	} catch (err) {
		console.warn('Error loading collections', err)
	}
}

function onCreateCalendar(uri: string, displayName: string) {
	calendars.value.push({ id: 0, uri, displayName })
	selectedCalendar.value = uri
}

function onCreateAddressbook(uri: string, displayName: string) {
	addressbooks.value.push({ id: 0, uri, displayName })
	selectedAddressbook.value = uri
}

async function saveCollections() {
	try {
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/user/collections')
		await axios.post(url, {
			calendar: selectedCalendar.value,
			addressbook: selectedAddressbook.value,
		})
		saveSuccess.value = true
		setTimeout(() => { saveSuccess.value = false }, 2000)
	} catch (err) {
		console.error('Error saving collections', err)
	}
}

onMounted(() => {
	loadCollections()
})
</script>

<style scoped>
#sendentsynchroniser-user-settings {
	padding: 20px;
	max-width: 800px;
}

#sendentsynchroniser-user-settings h2 {
	font-size: 20px;
	font-weight: 700;
	margin-bottom: 16px;
}

.user-settings__collections {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.user-settings__collections h3 {
	font-size: 16px;
	font-weight: 600;
	margin-bottom: 4px;
}

.user-settings__subtitle {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.user-settings__actions {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 16px;
}

.user-settings__saved {
	color: var(--color-success-text);
	font-weight: 600;
	animation: fadeIn 0.3s;
}

@keyframes fadeIn {
	from { opacity: 0; }
	to { opacity: 1; }
}
</style>
