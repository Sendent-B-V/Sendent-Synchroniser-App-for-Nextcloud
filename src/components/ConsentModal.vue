<template>
	<div v-if="visible"
		class="consent-modal__overlay"
		@click.self="close">
		<div class="consent-modal__content">
			<div class="consent-modal__header">
				<h2>{{ t('sendentsynchroniser', 'Sendent synchronisation not active') }}</h2>
				<a href="#"
					class="consent-modal__close"
					@click.prevent="close">&times;</a>
			</div>
			<div class="consent-modal__body">
				<ConsentFlow :active-user="false"
					:is-modal="true"
					@close="close" />
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import ConsentFlow from './ConsentFlow.vue'

const visible = ref(true)

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
