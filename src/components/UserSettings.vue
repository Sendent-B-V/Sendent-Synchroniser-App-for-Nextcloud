<template>
	<div id="sendentsynchroniser-user-settings">
		<h2>Sendent Sync</h2>
		<ConsentFlow :active-user="isActive"
			@consent-changed="isActive = true" />
		<RetractConsent v-if="isActive" />
	</div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import ConsentFlow from './ConsentFlow.vue'
import RetractConsent from './RetractConsent.vue'

const props = defineProps<{
	activeUser: boolean
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
