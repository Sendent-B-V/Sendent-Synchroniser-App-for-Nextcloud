<template>
	<div class="consent-flow">
		<template v-if="activeUser && step === 'idle'">
			<p class="consent-flow__message">
				{{ t('sendentsynchroniser', 'You have already succesfully provided your consent for syncing your data using the Nextcloud Exchange Connector.') }}
			</p>
		</template>
		<template v-else-if="!activeUser && step === 'idle'">
			<p class="consent-flow__message">
				{{ t('sendentsynchroniser', 'To ensure the seamless operation of the Nextcloud Exchange Connector, we need your permission to synchronize your Outlook with Nextcloud. This process consists of one or two simple step(s) and should only take a minute of your time.') }}
			</p>
		</template>

		<div class="consent-flow__content">
			<h3 v-if="title">{{ title }}</h3>
			<p v-if="text">{{ text }}</p>

			<!-- Collection selection step -->
			<template v-if="step === 'collections'">
				<div class="consent-flow__collections">
					<CollectionSelector
						:label="t('sendentsynchroniser', 'Target calendar')"
						:collections="calendars"
						v-model="selectedCalendar"
						:create-placeholder="t('sendentsynchroniser', 'New calendar name')"
						@create="onCreateCalendar" />
					<CollectionSelector
						:label="t('sendentsynchroniser', 'Target addressbook')"
						:collections="addressbooks"
						v-model="selectedAddressbook"
						:create-placeholder="t('sendentsynchroniser', 'New addressbook name')"
						@create="onCreateAddressbook" />
				</div>
			</template>

			<div v-if="showButton" class="consent-flow__actions">
				<button class="primary" @click="handleClick">{{ buttonLabel }}</button>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import CollectionSelector from './CollectionSelector.vue'

type Step = 'idle' | 'step1' | 'collections' | 'step2' | 'complete'

interface CollectionItem {
	id: number
	uri: string
	displayName: string
}

const props = defineProps<{
	activeUser: boolean
	isModal?: boolean
}>()

const emit = defineEmits<{
	close: []
	'consent-changed': []
}>()

const step = ref<Step>('idle')
const title = ref('')
const text = ref('')
const buttonLabel = ref('')
const showButton = ref(true)

// Collection data from activate() response
const calendars = ref<CollectionItem[]>([])
const addressbooks = ref<CollectionItem[]>([])
const selectedCalendar = ref('')
const selectedAddressbook = ref('')

// Set initial state based on activeUser
if (props.activeUser) {
	title.value = t('sendentsynchroniser', 'Give consent')
	text.value = t('sendentsynchroniser', 'You can refresh your consent by clicking the button below.')
	buttonLabel.value = t('sendentsynchroniser', 'Refresh consent')
} else {
	title.value = ''
	text.value = t('sendentsynchroniser', 'Please click the button below to sync your Outlook appointments, contacts, and tasks with Nextcloud.')
	buttonLabel.value = t('sendentsynchroniser', 'Start consent flow')
}

async function onCreateCalendar(uri: string, displayName: string) {
	// The backend createCalendar is implicitly done via ensureDefaultCollections,
	// but for user-created ones we just add it to the list optimistically
	// and it will be created when setCollections is called
	calendars.value.push({ id: 0, uri, displayName })
	selectedCalendar.value = uri
}

async function onCreateAddressbook(uri: string, displayName: string) {
	addressbooks.value.push({ id: 0, uri, displayName })
	selectedAddressbook.value = uri
}

async function handleClick() {
	if (step.value === 'idle') {
		step.value = 'step1'
		title.value = t('sendentsynchroniser', 'Step 1: Set up appointments, contacts, and tasks')
		text.value = t('sendentsynchroniser', 'Please click the button below to allow synchronisation of your Outlook appointments, contacts, and tasks with Nextcloud.')
		buttonLabel.value = t('sendentsynchroniser', 'Give access')
		return
	}

	if (step.value === 'step1') {
		try {
			const url = generateUrl('/apps/sendentsynchroniser/api/1.0/user/activate')
			const response = await axios.get(url)
			if (response.status !== 200) return

			// Populate collection pickers from activate() response
			calendars.value = response.data.calendars || []
			addressbooks.value = response.data.addressbooks || []
			selectedCalendar.value = response.data.defaultCalendar || 'personal'
			selectedAddressbook.value = response.data.defaultAddressbook || 'contacts'

			// Move to collection selection step
			step.value = 'collections'
			title.value = t('sendentsynchroniser', 'Step 2: Choose your sync targets')
			text.value = t('sendentsynchroniser', 'Select which calendar and addressbook should receive your Exchange data.')
			buttonLabel.value = t('sendentsynchroniser', 'Continue')

			// Store activate response for later
			;(window as any).__sendentActivateResponse = response.data

		} catch (err) {
			console.warn('Error during consent flow activation', err)
		}
		return
	}

	if (step.value === 'collections') {
		// Save collection choices
		try {
			const url = generateUrl('/apps/sendentsynchroniser/api/1.0/user/collections')
			await axios.post(url, {
				calendar: selectedCalendar.value,
				addressbook: selectedAddressbook.value,
			})
		} catch (err) {
			console.warn('Error saving collection choices', err)
		}

		const activateData = (window as any).__sendentActivateResponse || {}

		if (activateData.shouldAskMailSync) {
			const domain = activateData.emailDomain
			const accountsUrl = generateUrl('/apps/mail/api/accounts')
			const accountsResp = await axios.get(accountsUrl)

			if (accountsResp.data.length === 0) {
				step.value = 'step2'
				title.value = t('sendentsynchroniser', 'Step 3: Set up mail')
				text.value = t('sendentsynchroniser', "Your Outlook appointments, contacts, and tasks are now synchronised with Nextcloud. By clicking the button below you'll be redirected to the Mail application to set it up.")
				buttonLabel.value = t('sendentsynchroniser', 'Finish')
			} else {
				const account = accountsResp.data[0]
				if (account.emailAddress.endsWith(domain)) {
					step.value = 'complete'
					title.value = t('sendentsynchroniser', 'Configuration complete')
					text.value = t('sendentsynchroniser', 'Your Outlook appointments, contacts, and tasks are now synchronised with Nextcloud. And, your Exchange mailbox seems properly setup in the Mail application. You may close this window')
					buttonLabel.value = t('sendentsynchroniser', 'Close')
				} else {
					step.value = 'step2'
					title.value = t('sendentsynchroniser', 'Step 3: Set up mail')
					text.value = t('sendentsynchroniser', "Your Outlook appointments, contacts, and tasks are now synchronised with Nextcloud. But, your Exchange mailbox doesn't seem properly setup in the Mail application. Please click the button below to grant permission for accessing your Exchange mailbox.")
					buttonLabel.value = t('sendentsynchroniser', 'Finish')
				}
			}
		} else {
			step.value = 'complete'
			title.value = t('sendentsynchroniser', 'Configuration complete')
			text.value = t('sendentsynchroniser', 'Your account is fully configured for Exchange synchronization. You may close this window')
			buttonLabel.value = t('sendentsynchroniser', 'Close')
			showButton.value = !props.isModal
		}

		emit('consent-changed')
		return
	}

	if (step.value === 'step2') {
		window.open(generateUrl('/apps/mail'), '_self')
		return
	}

	if (step.value === 'complete') {
		emit('close')
	}
}
</script>

<style scoped>
.consent-flow__message {
	margin-bottom: 16px;
}

.consent-flow__content h3 {
	font-size: 16px;
	font-weight: 600;
	margin-bottom: 8px;
}

.consent-flow__content p {
	margin-bottom: 12px;
}

.consent-flow__collections {
	margin: 16px 0;
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.consent-flow__actions {
	margin-top: 12px;
}
</style>
