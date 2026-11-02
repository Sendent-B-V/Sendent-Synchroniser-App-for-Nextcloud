<template>
	<div id="sendentsynchroniser-user-settings">
		<h2>{{ t('sendentsynchroniser', 'Synchronizer') }}</h2>
		<ConsentFlow :active-user="isActive"
			:graph-auth-method="graphAuthMethod"
			:auth-status="authStatus"
			@consent-changed="isActive = true" />
		<RetractConsent v-if="isActive" />
	</div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import ConsentFlow from './ConsentFlow.vue'
import RetractConsent from './RetractConsent.vue'
import type { AuthStatus } from '../types/authStatus'

const props = defineProps<{
	activeUser: boolean
	graphAuthMethod: string
	/** The stored Microsoft status, null until the service has reported one. */
	authStatus: AuthStatus | null
}>()

const isActive = ref(props.activeUser)
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
</style>
