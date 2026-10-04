<template>
	<div class="settings-section">
		<h3>{{ t('sendentsynchroniser', 'Change notifications') }}</h3>
		<p class="settings-section__hint">
			{{ t('sendentsynchroniser', 'How the Exchange Connector learns that a calendar or address book changed. Nextcloud only tells the Connector to check; the Connector then reads which collections changed. No event or contact data is sent.') }}
		</p>
		<p v-if="saveError" class="settings-section__hint settings-section__hint--warning">
			{{ saveError }}
		</p>

		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Transport') }}</label>
			<div class="settings-section__input-row">
				<select v-model="transportMode"
					class="settings-section__input"
					@change="saveTransportMode">
					<option value="auto">
						{{ t('sendentsynchroniser', 'notify_push when available, otherwise polling') }}
					</option>
					<option value="polling">
						{{ t('sendentsynchroniser', 'Polling only') }}
					</option>
				</select>
				<span v-if="saved.transportMode" class="settings-section__saved">&#x2713;</span>
			</div>
		</div>

		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Setup check') }}</label>
			<div v-if="check" class="cn-status">
				<div v-if="!check.server_supported" class="cn-status__line cn-status__line--fail">
					{{ t('sendentsynchroniser', 'This Nextcloud is older than 32 — change notifications require Nextcloud 32 or later.') }}
				</div>
				<div :class="['cn-status__line', check.notify_push.app_enabled ? 'cn-status__line--ok' : 'cn-status__line--fail']">
					{{ check.notify_push.app_enabled
						? t('sendentsynchroniser', 'notify_push app: enabled ✓')
						: t('sendentsynchroniser', 'notify_push app: not installed ✗ — in Nextcloud AIO it ships enabled; on other installs, install the "Client Push" app and run occ notify_push:setup') }}
				</div>
				<div :class="['cn-status__line', check.notify_push.queue_available ? 'cn-status__line--ok' : 'cn-status__line--fail']">
					{{ check.notify_push.queue_available
						? t('sendentsynchroniser', 'Redis queue: available ✓')
						: t('sendentsynchroniser', 'Redis queue: unavailable ✗ — configure Redis as the distributed cache') }}
				</div>
				<div :class="['cn-status__line', check.notify_push.daemon.ok ? 'cn-status__line--ok' : 'cn-status__line--fail']">
					{{ check.notify_push.daemon.ok
						? t('sendentsynchroniser', 'Push daemon: reachable ✓')
						: t('sendentsynchroniser', 'Push daemon: {message} ✗', { message: check.notify_push.daemon.message }) }}
				</div>
				<div :class="['cn-status__line', check.notify_push.ok ? 'cn-status__line--ok' : 'cn-status__line--fail']">
					{{ check.notify_push.message }}
				</div>
			</div>
			<div class="settings-section__input-row">
				<button type="button" :disabled="checking" @click="runCheck">
					{{ checking ? t('sendentsynchroniser', 'Testing…') : t('sendentsynchroniser', 'Run test') }}
				</button>
			</div>
		</div>

		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Service account (bot user)') }}</label>
			<div class="settings-section__input-row">
				<input v-model="botUser"
					type="text"
					class="settings-section__input"
					:placeholder="t('sendentsynchroniser', 'e.g. sendent-sync')"
					@change="saveBotUser">
				<span v-if="saved.botUser" class="settings-section__saved">&#x2713;</span>
			</div>
			<p class="settings-section__hint">
				{{ t('sendentsynchroniser', 'This account receives change hints and reads the change feed only; it needs no group memberships, quota or calendars. Create it and an app password for the Connector with occ user:add and occ user:auth-tokens:add; occ user:auth-tokens:list shows when the Connector last used it.') }}
			</p>
		</div>

		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Poll interval (s)') }}</label>
			<div class="settings-section__input-row">
				<input v-model="pollInterval"
					type="number"
					min="5"
					max="300"
					@change="savePollInterval">
				<span v-if="saved.pollInterval" class="settings-section__saved">&#x2713;</span>
			</div>
			<p class="settings-section__hint">
				{{ t('sendentsynchroniser', 'How often the Connector checks for changes while notify_push is unavailable. With notify_push it checks on every hint instead.') }}
			</p>
		</div>
	</div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

interface SetupCheck {
	ok: boolean
	server_supported: boolean
	notify_push: {
		ok: boolean
		app_enabled: boolean
		queue_available: boolean
		daemon: { ok: boolean, message: string }
		ws_url: string | null
		websocket_secure: boolean
		effective_transport: string
		message: string
	}
}

const props = defineProps<{
	initialTransportMode: string
	initialBotUser: string
	initialPollInterval: string
	notifyPushInstalled: boolean
}>()

// A retired 'notify_push' (forced) value reads as 'auto' on the server too.
const transportMode = ref(props.initialTransportMode === 'polling' ? 'polling' : 'auto')
const botUser = ref(props.initialBotUser)
const pollInterval = ref(props.initialPollInterval)

const checking = ref(false)
const check = ref<SetupCheck | null>(null)
const saveError = ref<string | null>(null)

const saved = reactive<Record<string, boolean>>({})

/** @param key feedback key to flash briefly */
function showSaved(key: string) {
	saved[key] = true
	setTimeout(() => { saved[key] = false }, 1500)
}

/**
 * @param endpoint settings endpoint under /api/1.0/settings/
 * @param data POST body
 * @param feedbackKey feedback key to flash on success
 */
async function saveSetting(endpoint: string, data: Record<string, string | number>, feedbackKey: string) {
	const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/' + endpoint)
	try {
		await axios.post(url, data)
		saveError.value = null
		showSaved(feedbackKey)
	} catch (e) {
		const message = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
		saveError.value = message || t('sendentsynchroniser', 'Saving failed')
		console.error('Failed to save setting:', endpoint)
	}
}

/** */
function saveTransportMode() { saveSetting('cnTransportMode', { mode: transportMode.value }, 'transportMode') }
/** */
function saveBotUser() { saveSetting('cnBotUser', { uid: botUser.value }, 'botUser') }
/** */
function savePollInterval() { saveSetting('cnPollInterval', { pollInterval: Number(pollInterval.value) }, 'pollInterval') }

/** The same check as occ sendentsynchroniser:cn-check; probes the push daemon. */
async function runCheck() {
	checking.value = true
	try {
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/cnCheck')
		check.value = (await axios.post(url)).data as SetupCheck
	} catch {
		console.error('Change-notification setup check failed')
	} finally {
		checking.value = false
	}
}

onMounted(() => {
	if (props.notifyPushInstalled) {
		runCheck()
	}
})
</script>

<style scoped lang="scss">
.settings-section {
	margin-bottom: 24px;
}

.settings-section__field {
	margin-bottom: 12px;
}

.settings-section__hint {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0 0;
	max-width: 400px;
}

.settings-section__hint--warning {
	color: var(--color-error, #d91f2d);
	font-weight: bold;
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

.settings-section__saved {
	color: var(--color-success-text);
	font-weight: 600;
	animation: fadeIn 0.3s;
}

@keyframes fadeIn {
	from { opacity: 0; }
	to { opacity: 1; }
}

.cn-status {
	margin: 8px 0;

	&__line {
		font-size: 0.9em;
		line-height: 1.6;

		&--ok {
			color: var(--color-success, #2d7b41);
		}

		&--fail {
			color: var(--color-error, #d91f2d);
		}
	}
}
</style>
