<template>
	<div class="license-section">
		<h3>License</h3>
		<div v-if="licenseStore.loading" class="license-section__loading">
			<span class="icon-loading" />
			Loading license status...
		</div>
		<template v-else>
			<div class="license-section__info">
				<p>Find out how to configure your license <a href="https://sendent.freshdesk.com/support/solutions/articles/80000592300-configuring-your-license">here</a>.</p>
				<p>{{ t('sendentsynchroniser', "You only need a license key if you are using one of the paid plans of Sendent. If you don't have a valid license key anymore, you will automatically be downgraded to Sendent Free.") }}</p>
			</div>

			<div class="license-section__form">
				<div class="license-section__field">
					<label>{{ t('sendentsynchroniser', 'Email address') }}</label>
					<input v-model="licenseStore.email"
						type="email"
						placeholder="Enter email address">
				</div>
				<div class="license-section__field">
					<label>{{ t('sendentsynchroniser', 'License key') }}</label>
					<input v-model="licenseStore.licenseKey"
						type="text"
						placeholder="Enter license key">
				</div>
				<div class="license-section__actions">
					<button class="primary"
						:disabled="!licenseStore.email || !licenseStore.licenseKey"
						@click="onActivate">
						{{ t('sendentsynchroniser', 'Activate License') }}
					</button>
					<button @click="onClear">
						{{ t('sendentsynchroniser', 'Clear License') }}
					</button>
				</div>
			</div>

			<LicenseStatusDisplay :status="licenseStore.status" />

			<div v-if="licenseStore.status?.level === 'Offline_mode'" class="license-section__offline">
				{{ t('sendentsynchroniser', 'You are using license configuration in Offline mode') }}
			</div>
		</template>
	</div>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import { useLicenseStore } from '../stores/license'
import LicenseStatusDisplay from './LicenseStatusDisplay.vue'

const licenseStore = useLicenseStore()

async function onActivate() {
	await licenseStore.activateLicense()
}

async function onClear() {
	await licenseStore.clearLicense()
}
</script>

<style scoped>
.license-section {
	margin-bottom: 24px;
}

.license-section h3 {
	font-size: 16px;
	font-weight: 600;
	margin-bottom: 16px;
}

.license-section__loading {
	display: flex;
	align-items: center;
	gap: 8px;
	color: var(--color-text-maxcontrast);
}

.license-section__info {
	margin-bottom: 16px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.license-section__info a {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.license-section__form {
	margin-bottom: 16px;
}

.license-section__field {
	margin-bottom: 8px;
}

.license-section__field label {
	display: block;
	font-weight: 500;
	margin-bottom: 4px;
}

.license-section__field input {
	width: 100%;
	max-width: 400px;
}

.license-section__actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.license-section__offline {
	margin-top: 12px;
	font-style: italic;
	color: var(--color-text-maxcontrast);
}
</style>
