<template>
	<div class="settings-section">
		<h3>{{ t('sendentsynchroniser', 'Change notifications') }}</h3>
		<p class="settings-section__hint">
			{{ t('sendentsynchroniser', 'How the Exchange Connector learns that a calendar or address book changed. Signals carry only collection references, never event or contact data.') }}
		</p>

		<!-- Transport -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Transport') }}</label>
			<div class="settings-section__input-row">
				<select v-model="transportMode"
					class="settings-section__input"
					@change="saveTransportMode">
					<option value="auto">
						{{ t('sendentsynchroniser', 'Automatic (prefer notify_push)') }}
					</option>
					<option value="notify_push">
						{{ t('sendentsynchroniser', 'Force notify_push') }}
					</option>
					<option value="polling">
						{{ t('sendentsynchroniser', 'Force polling') }}
					</option>
				</select>
				<span v-if="saved.transportMode" class="settings-section__saved">&#x2713;</span>
			</div>
			<!-- Persistent while forced-but-unhealthy — including when the app
				 is not installed at all (testResult never loads then). -->
			<p v-if="transportMode === 'notify_push' && ((testResult && !testResult.daemon.ok) || !notifyPushInstalled)"
				class="settings-section__hint settings-section__hint--warning">
				{{ t('sendentsynchroniser', 'notify_push is forced but unhealthy. Signals may not be delivered; the Connector will fall back to reading the change feed.') }}
			</p>
		</div>

		<!-- notify_push status -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'notify_push status') }}</label>
			<div v-if="testResult" class="cn-status">
				<div :class="['cn-status__line', testResult.app_enabled ? 'cn-status__line--ok' : 'cn-status__line--fail']">
					{{ testResult.app_enabled
						? t('sendentsynchroniser', 'notify_push app: enabled ✓')
						: t('sendentsynchroniser', 'notify_push app: not installed ✗ — in Nextcloud AIO it ships enabled; on other installs, install the "Client Push" app and run occ notify_push:setup') }}
				</div>
				<div :class="['cn-status__line', testResult.queue_available ? 'cn-status__line--ok' : 'cn-status__line--fail']">
					{{ testResult.queue_available
						? t('sendentsynchroniser', 'Redis queue: available ✓')
						: t('sendentsynchroniser', 'Redis queue: unavailable ✗ — configure Redis as the distributed cache') }}
				</div>
				<div :class="['cn-status__line', testResult.daemon.ok ? 'cn-status__line--ok' : 'cn-status__line--fail']">
					{{ testResult.daemon.ok
						? t('sendentsynchroniser', 'Push daemon: reachable ✓')
						: t('sendentsynchroniser', 'Push daemon: unreachable ✗ — {message}', { message: testResult.daemon.message }) }}
				</div>
				<div class="cn-status__line">
					{{ t('sendentsynchroniser', 'Active transport: {transport}', { transport: testResult.effective_transport }) }}
				</div>
				<div v-if="roundTrip" :class="['cn-status__line', roundTrip.ok ? 'cn-status__line--ok' : 'cn-status__line--fail']">
					{{ roundTrip.ok
						? t('sendentsynchroniser', 'Publish test: {ms} ms ✓', { ms: String(roundTrip.ms) })
						: t('sendentsynchroniser', 'Publish test failed ✗') }}
				</div>
			</div>
			<div v-else-if="!notifyPushInstalled" class="cn-status">
				<div class="cn-status__line cn-status__line--fail">
					{{ t('sendentsynchroniser', 'notify_push app: not installed ✗') }}
				</div>
			</div>
			<div class="settings-section__input-row">
				<button type="button" :disabled="testing" @click="runTest">
					{{ testing ? t('sendentsynchroniser', 'Testing…') : t('sendentsynchroniser', 'Run test') }}
				</button>
			</div>
		</div>

		<!-- Service account -->
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
				{{ t('sendentsynchroniser', 'This account receives change signals and reads the change feed only; it needs no group memberships, quota or calendars. Create it first (Users administration or occ user:add), then generate an app password for it under its own Settings → Security and store that password in the Connector.') }}
			</p>
		</div>

		<!-- Batching -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Batching') }}</label>
			<div class="settings-section__input-row">
				<label class="cn-inline-label">{{ t('sendentsynchroniser', 'Batch window (s)') }}
					<input v-model="batchWindow" type="number" min="0" max="10" @change="saveBatching">
				</label>
				<label class="cn-inline-label">{{ t('sendentsynchroniser', 'Max references per signal') }}
					<input v-model="maxRefsPerSignal" type="number" min="1" max="5000" @change="saveBatching">
				</label>
				<label class="cn-inline-label">{{ t('sendentsynchroniser', 'Poll interval (s)') }}
					<input v-model="pollInterval" type="number" min="5" max="300" @change="saveBatching">
				</label>
				<span v-if="saved.batching" class="settings-section__saved">&#x2713;</span>
			</div>
		</div>

		<!-- Optional webhook -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Outbound webhook (optional)') }}</label>
			<div class="settings-section__input-row">
				<input v-model="webhookUrl"
					type="url"
					class="settings-section__input"
					:placeholder="t('sendentsynchroniser', 'https://connector.example.com/signals')"
					@change="saveWebhook">
				<input v-model="webhookSecret"
					type="password"
					class="settings-section__input"
					:placeholder="t('sendentsynchroniser', 'Shared secret (leave empty to keep current)')"
					@change="saveWebhook">
				<select v-model="webhookEnabled" @change="saveWebhook">
					<option value="true">{{ t('sendentsynchroniser', 'Enabled') }}</option>
					<option value="false">{{ t('sendentsynchroniser', 'Disabled') }}</option>
				</select>
				<button type="button"
					:disabled="webhookEnabled !== 'true'"
					@click="sendTestWebhook">
					{{ t('sendentsynchroniser', 'Send test') }}
				</button>
				<span v-if="saved.webhook" class="settings-section__saved">&#x2713;</span>
			</div>
			<p class="settings-section__hint">
				{{ t('sendentsynchroniser', 'Additionally POST each signal to this URL, signed with HMAC-SHA256. A hint only — the Connector still reads the change feed. Requires Nextcloud to reach the Connector.') }}
			</p>
		</div>

		<!-- Diagnostics -->
		<div class="settings-section__field">
			<label>{{ t('sendentsynchroniser', 'Diagnostics') }}</label>
			<div v-if="health" class="cn-status">
				<div class="cn-status__line">
					{{ t('sendentsynchroniser', 'Collections tracked: {n}', { n: String(health.ledger_rows) }) }}
				</div>
				<div class="cn-status__line">
					{{ t('sendentsynchroniser', 'Current cursor: {n}', { n: String(health.cursor) }) }}
				</div>
				<div class="cn-status__line">
					{{ health.ack_at > 0
						? t('sendentsynchroniser', 'Connector acknowledged cursor {ack} (lag {lag})', { ack: String(health.ack_cursor), lag: String(health.connector_lag) })
						: t('sendentsynchroniser', 'Connector has not acknowledged yet') }}
				</div>
				<div v-if="health.signals_last_hour" class="cn-status__line">
					{{ t('sendentsynchroniser', 'Signals last hour: {n} flushes · avg {avg} refs/signal · max {max} (truncated ×{tr})', {
						n: String(health.signals_last_hour.flushes),
						avg: health.signals_last_hour.flushes > 0
							? (health.signals_last_hour.refs / health.signals_last_hour.flushes).toFixed(1)
							: '0',
						max: String(health.signals_last_hour.max_refs),
						tr: String(health.signals_last_hour.truncated),
					}) }}
				</div>
			</div>
			<div class="settings-section__input-row">
				<button type="button" @click="refreshHealth">
					{{ t('sendentsynchroniser', 'Refresh') }}
				</button>
				<button type="button" :disabled="flushing" @click="flushNow">
					{{ flushing ? t('sendentsynchroniser', 'Flushing…') : t('sendentsynchroniser', 'Flush now') }}
				</button>
				<span v-if="saved.flush" class="settings-section__saved">&#x2713;</span>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

interface TestResult {
	app_enabled: boolean
	queue_available: boolean
	daemon: { ok: boolean, at: number, message: string }
	effective_transport: string
}

interface Health {
	transport: string
	notify_push_ok: boolean
	last_signal_at: number
	ledger_rows: number
	cursor: number
	ack_cursor: number
	ack_at: number
	connector_lag: number
	signals_last_hour?: { flushes: number, refs: number, truncated: number, max_refs: number }
}

const props = defineProps<{
	initialTransportMode: string
	initialBotUser: string
	initialBatchWindow: string
	initialMaxRefsPerSignal: string
	initialPollInterval: string
	notifyPushInstalled: boolean
	initialWebhookUrl: string
	initialWebhookEnabled: string
}>()

const transportMode = ref(props.initialTransportMode)
const botUser = ref(props.initialBotUser)
const batchWindow = ref(props.initialBatchWindow)
const maxRefsPerSignal = ref(props.initialMaxRefsPerSignal)
const pollInterval = ref(props.initialPollInterval)
const webhookUrl = ref(props.initialWebhookUrl)
const webhookSecret = ref('')
const webhookEnabled = ref(props.initialWebhookEnabled === 'true' ? 'true' : 'false')

const testing = ref(false)
const flushing = ref(false)
const testResult = ref<TestResult | null>(null)
const health = ref<Health | null>(null)
const roundTrip = ref<{ ok: boolean, ms: number } | null>(null)

const notifyPushInstalled = props.notifyPushInstalled

const saved = reactive<Record<string, boolean>>({})

/**
 * @param key feedback key to flash
 */
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
		showSaved(feedbackKey)
	} catch {
		console.error('Failed to save setting:', endpoint)
	}
}

/** */
function saveTransportMode() { saveSetting('cnTransportMode', { mode: transportMode.value }, 'transportMode') }
/** */
function saveBotUser() { saveSetting('cnBotUser', { uid: botUser.value }, 'botUser') }
/** */
function saveBatching() {
	saveSetting('cnBatching', {
		batchWindow: Number(batchWindow.value),
		maxRefsPerSignal: Number(maxRefsPerSignal.value),
		pollInterval: Number(pollInterval.value),
	}, 'batching')
}

/** */
function saveWebhook() {
	saveSetting('cnWebhook', {
		url: webhookUrl.value,
		secret: webhookSecret.value,
		enabled: webhookEnabled.value === 'true' ? 1 : 0,
	}, 'webhook')
}

/** */
async function sendTestWebhook() {
	try {
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/cnWebhookTest')
		await axios.post(url)
		showSaved('webhook')
	} catch {
		console.error('Webhook test failed')
	}
}

/** */
async function runTest() {
	testing.value = true
	try {
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/cnRunTest')
		testResult.value = (await axios.post(url)).data as TestResult
		if (testResult.value?.daemon.ok) {
			await runRoundTrip()
		}
	} catch {
		console.error('notify_push test failed')
	} finally {
		testing.value = false
	}
}

/**
 * Publish test (plan deviation 7): asks the server to publish a ping addressed
 * to the BOT user and measures the request round-trip. The admin session
 * cannot see bot-addressed frames, so this verifies and times the PUBLISH side
 * only; end-to-end delivery confirmation is the Connector's own startup check.
 */
async function runRoundTrip() {
	const started = Date.now()
	try {
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/cnSendPing')
		const { data } = await axios.post(url)
		const ms = Date.now() - started
		roundTrip.value = { ok: Boolean(data.published), ms }
		const report = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/cnReportPing')
		await axios.post(report, { ok: Boolean(data.published), ms })
	} catch {
		roundTrip.value = { ok: false, ms: 0 }
	}
}

/** */
async function refreshHealth() {
	try {
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/notify/health')
		health.value = (await axios.get(url)).data as Health
	} catch {
		console.error('Failed to load change-notification health')
	}
}

/** */
async function flushNow() {
	flushing.value = true
	try {
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/cnFlushNow')
		await axios.post(url)
		showSaved('flush')
		await refreshHealth()
	} catch {
		console.error('Flush failed')
	} finally {
		flushing.value = false
	}
}

onMounted(() => {
	refreshHealth()
	if (props.notifyPushInstalled) {
		runTest()
	}
})
</script>

<style scoped lang="scss">
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

.cn-inline-label {
	display: inline-flex;
	flex-direction: column;
	margin-right: 12px;
	font-size: 0.85em;

	input {
		width: 90px;
	}
}
</style>
