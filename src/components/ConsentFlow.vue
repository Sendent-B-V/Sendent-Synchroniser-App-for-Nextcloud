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
			<h3 v-if="title">
				{{ title }}
			</h3>
			<p v-if="text">
				{{ text }}
			</p>

			<div v-if="showButton" class="consent-flow__actions">
				<button class="primary" @click="handleClick">
					{{ buttonLabel }}
				</button>
				<button v-if="secondaryLabel" @click="handleSecondary">
					{{ secondaryLabel }}
				</button>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

type Step = 'idle' | 'step1' | 'reset' | 'step2' | 'complete'

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
const secondaryLabel = ref('')
const showButton = ref(true)

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

/**
 * Activates sync for the user and advances to the mail step or completion.
 */
async function doActivate() {
	try {
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/user/activate')
		const response = await axios.get(url)
		if (response.status !== 200) return

		// activate() silently ensures default collections exist (admin-configured)
		// No user selection — collections are managed by the administrator

		if (response.data.shouldAskMailSync) {
			const domain = response.data.emailDomain as string
			const accountsUrl = generateUrl('/apps/mail/api/accounts')
			const accountsResp = await axios.get(accountsUrl)

			if (accountsResp.data.length === 0) {
				step.value = 'step2'
				title.value = t('sendentsynchroniser', 'Step 2: Set up mail')
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
					title.value = t('sendentsynchroniser', 'Step 2: Set up mail')
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
	} catch (err) {
		console.warn('Error during consent flow activation', err)
	}
}

/**
 * Declines the one-time calendar reset and continues with activation. No
 * server call needed: activation swaps the app-token name, which is what
 * prevents the offer from appearing again.
 */
async function handleSecondary() {
	if (step.value !== 'reset') return
	secondaryLabel.value = ''
	await doActivate()
}

/**
 * Advances the consent flow.
 */
async function handleClick() {
	if (step.value === 'idle') {
		step.value = 'step1'
		title.value = t('sendentsynchroniser', 'Step 1: Set up appointments, contacts, and tasks')
		text.value = t('sendentsynchroniser', 'Please click the button below to allow synchronisation of your Outlook appointments, contacts, and tasks with Nextcloud.')
		buttonLabel.value = t('sendentsynchroniser', 'Give access')
		return
	}

	if (step.value === 'step1') {
		// One-time offer for legacy sync users: reset the synced calendar
		// before activation so the new sync starts from a clean calendar.
		try {
			const statusUrl = generateUrl('/apps/sendentsynchroniser/api/1.0/user/calendarReset/status')
			const statusResp = await axios.get(statusUrl)
			if (statusResp.data.applicable) {
				step.value = 'reset'
				title.value = t('sendentsynchroniser', 'One-time calendar clean-up')
				text.value = t('sendentsynchroniser', 'We found appointments from a previous version of the Exchange synchronisation in your calendar. To avoid duplicate appointments, we recommend deleting and re-creating this calendar before continuing. Warning: this permanently removes all events in the calendar — including ones created in Nextcloud — and removes any shares on it. Your Outlook calendar is not affected and will be synchronised into the new calendar afterwards.')
				buttonLabel.value = t('sendentsynchroniser', 'Delete and re-create calendar')
				secondaryLabel.value = t('sendentsynchroniser', 'Keep my calendar as it is')
				return
			}
		} catch (err) {
			console.warn('Calendar reset status check failed, continuing without it', err)
		}
		await doActivate()
		return
	}

	if (step.value === 'reset') {
		try {
			await axios.post(generateUrl('/apps/sendentsynchroniser/api/1.0/user/calendarReset'))
		} catch (err) {
			console.warn('Calendar reset failed', err)
		}
		secondaryLabel.value = ''
		await doActivate()
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

.consent-flow__actions {
	margin-top: 12px;
}

.consent-flow__actions button + button {
	margin-inline-start: 8px;
}
</style>
