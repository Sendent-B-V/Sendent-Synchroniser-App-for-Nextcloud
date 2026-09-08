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

			<p v-if="errorText" class="consent-flow__error" role="alert">
				{{ errorText }}
			</p>

			<div v-if="showButton" class="consent-flow__actions">
				<button class="primary" :disabled="busy" @click="handleClick">
					{{ buttonLabel }}
				</button>
				<button v-if="secondaryLabel" :disabled="busy" @click="handleSecondary">
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
const busy = ref(false)
const errorText = ref('')
// Set once the server answered OK or Skipped: further clicks only activate.
const resetSettled = ref(false)

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

		errorText.value = ''
		secondaryLabel.value = ''
		emit('consent-changed')
	} catch (err) {
		console.warn('Error during consent flow activation', err)
		errorText.value = t('sendentsynchroniser', 'Activation failed. Please try again.')
	}
}

/** Activates without the clean-up: declined, or the status check failed and the user chose to continue. */
async function handleSecondary() {
	if (busy.value) return
	const canContinue = secondaryLabel.value !== ''
	if (!canContinue) return
	busy.value = true
	try {
		await doActivate()
	} finally {
		busy.value = false
	}
}

/**
 * Advances the consent flow.
 */
async function handleClick() {
	if (busy.value) return

	if (step.value === 'idle') {
		step.value = 'step1'
		title.value = t('sendentsynchroniser', 'Step 1: Set up appointments, contacts, and tasks')
		text.value = t('sendentsynchroniser', 'Please click the button below to allow synchronisation of your Outlook appointments, contacts, and tasks with Nextcloud.')
		buttonLabel.value = t('sendentsynchroniser', 'Give access')
		return
	}

	if (step.value === 'step1') {
		busy.value = true
		try {
			errorText.value = ''
			let applicable = false
			try {
				const statusUrl = generateUrl('/apps/sendentsynchroniser/api/1.0/user/calendarReset/status')
				const statusResp = await axios.get(statusUrl)
				applicable = statusResp.data?.applicable === true
			} catch (err) {
				console.warn('Calendar reset status check failed', err)
				// Activation settles the offer server-side; never fall through silently.
				errorText.value = t('sendentsynchroniser', 'We could not check whether a one-time calendar clean-up is needed. You can try again or continue without it.')
				buttonLabel.value = t('sendentsynchroniser', 'Try again')
				secondaryLabel.value = t('sendentsynchroniser', 'Continue without clean-up')
				return
			}

			if (applicable) {
				step.value = 'reset'
				title.value = t('sendentsynchroniser', 'One-time calendar clean-up')
				text.value = t('sendentsynchroniser', 'We found appointments from a previous version of the Exchange synchronisation in your calendar. To avoid duplicate appointments, we recommend deleting and re-creating this calendar before continuing. Warning: this permanently removes all events in the calendar — including ones created in Nextcloud — and removes any shares on it. Your Outlook calendar is not affected and will be synchronised into the new calendar afterwards.')
				buttonLabel.value = t('sendentsynchroniser', 'Delete and re-create calendar')
				secondaryLabel.value = t('sendentsynchroniser', 'Keep my calendar as it is')
				return
			}

			await doActivate()
		} finally {
			busy.value = false
		}
		return
	}

	if (step.value === 'reset') {
		busy.value = true
		try {
			errorText.value = ''
			if (resetSettled.value) {
				await doActivate()
				return
			}

			let status = 'Error'
			try {
				const resp = await axios.post(generateUrl('/apps/sendentsynchroniser/api/1.0/user/calendarReset'))
				status = resp.data?.status ?? 'Error'
			} catch (err) {
				console.warn('Calendar reset failed', err)
			}

			// Skipped/Error: nothing changed server-side.
			if (status === 'Skipped') {
				// Retrying would only ever return Skipped again, so offer to move on.
				resetSettled.value = true
				errorText.value = t('sendentsynchroniser', 'The calendar clean-up is no longer applicable to your account. You can continue without it.')
				buttonLabel.value = t('sendentsynchroniser', 'Continue')
				secondaryLabel.value = ''
				return
			}

			if (status !== 'OK') {
				errorText.value = t('sendentsynchroniser', 'The calendar clean-up could not be completed and your calendar was left unchanged. You can try again or continue without it.')
				return
			}

			// Calendar re-created; never offer the destructive action again.
			resetSettled.value = true
			buttonLabel.value = t('sendentsynchroniser', 'Continue')
			secondaryLabel.value = ''
			await doActivate()
		} finally {
			busy.value = false
		}
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

.consent-flow__error {
	color: var(--color-error-text, #b00020);
	margin-bottom: 12px;
}
</style>
