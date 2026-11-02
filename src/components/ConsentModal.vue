<template>
	<div v-if="visible"
		class="consent-modal__overlay"
		@click.self="close">
		<div class="consent-modal__content">
			<div class="consent-modal__header">
				<h2>{{ headerText }}</h2>
				<a href="#"
					class="consent-modal__close"
					@click.prevent="close">&times;</a>
			</div>
			<div class="consent-modal__body">
				<!-- Rendered once the status is known, so the flow opens on the right text. -->
				<ConsentFlow v-if="loaded"
					:active-user="activeUser"
					:is-modal="true"
					:graph-auth-method="signInMode"
					:auth-status="authStatus"
					@close="close" />
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import ConsentFlow from './ConsentFlow.vue'
import { needsMicrosoftAttention } from '../types/authStatus'
import type { AuthStatus, MyStatusResponse } from '../types/authStatus'

const visible = ref(true)
const loaded = ref(false)
const activeUser = ref(false)
const signInMode = ref('')
const authStatus = ref<AuthStatus | null>(null)

// The header names the problem: missing consent, or a Microsoft sign-in to fix.
const headerText = computed(() => {
	if (!needsMicrosoftAttention(activeUser.value, signInMode.value, authStatus.value)) {
		return t('sendentsynchroniser', 'Sendent synchronisation not active')
	}
	return authStatus.value?.status === 'needsReconnect'
		? t('sendentsynchroniser', 'Microsoft sign-in needs attention')
		: t('sendentsynchroniser', 'Microsoft sign-in required')
})

/**
 * The modal is mounted on a plain "show it" answer and has no initial state,
 * so it asks for the user's own sign-in status before rendering the flow.
 * If that fails it falls back to the plain consent flow, as before.
 */
onMounted(async () => {
	try {
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/graphAuth/status')
		const resp = await axios.get<MyStatusResponse>(url)
		activeUser.value = resp.data.activeUser
		signInMode.value = resp.data.signInMode
		authStatus.value = resp.data.status
	} catch (err) {
		console.warn('Could not read the Microsoft sign-in status', err)
	} finally {
		loaded.value = true
	}
})

/**
 *
 */
function close() {
	visible.value = false
}
</script>

<style scoped>
.consent-modal__overlay {
	position: fixed;
	inset: 0;
	z-index: 10000;
	background: rgba(0, 0, 0, 0.6);
}

.consent-modal__content {
	position: fixed;
	left: 50%;
	top: 50%;
	transform: translate(-50%, -50%);
	z-index: 11000;
	width: 700px;
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 2px 20px rgba(0, 0, 0, 0.3);
}

.consent-modal__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 16px 20px;
	border-bottom: 1px solid var(--color-border);
}

.consent-modal__header h2 {
	font-size: 18px;
	font-weight: 600;
	margin: 0;
}

.consent-modal__close {
	color: var(--color-text-maxcontrast);
	font-size: 24px;
	text-decoration: none;
	padding: 4px 8px;
}

.consent-modal__close:hover {
	color: var(--color-main-text);
}

.consent-modal__body {
	padding: 20px;
}
</style>
