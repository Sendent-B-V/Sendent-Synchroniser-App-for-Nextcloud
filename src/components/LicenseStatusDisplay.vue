<template>
	<div v-if="status" class="license-status">
		<div class="license-status__row">
			<span class="license-status__label">Status:</span>
			<span class="license-status__value"
				:class="statusClass"
				v-html="status.status" />
		</div>
		<div v-if="status.level" class="license-status__row">
			<span class="license-status__label">Level:</span>
			<span class="license-status__value">
				{{ status.level === 'Offline_mode' ? 'Offline mode' : status.level }}
			</span>
		</div>
		<div v-if="status.dateExpiration" class="license-status__row">
			<span class="license-status__label">Expiration:</span>
			<span class="license-status__value">{{ formatDate(status.dateExpiration) }}</span>
		</div>
		<div v-if="status.dateLastCheck" class="license-status__row">
			<span class="license-status__label">Last check:</span>
			<span class="license-status__value">{{ formatDate(status.dateLastCheck) }}</span>
		</div>
	</div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { LicenseStatus } from '../stores/license'

const props = defineProps<{
	status: LicenseStatus | null
}>()

function formatDate(dateStr: string): string {
	if (!dateStr) return '-'
	const date = new Date(dateStr)
	if (isNaN(date.getTime())) return dateStr
	return date.toLocaleDateString('nl-NL', { timeZone: 'UTC' })
}

const statusClass = computed(() => {
	if (!props.status) return ''
	switch (props.status.statusKind) {
	case 'valid': return 'license-status__value--valid'
	case 'expired': return 'license-status__value--expired'
	case 'nolicense': return 'license-status__value--none'
	case 'userlimit': return 'license-status__value--warning'
	case 'check': return 'license-status__value--error'
	case 'error_incomplete': return 'license-status__value--error'
	default: return ''
	}
})
</script>

<style scoped>
.license-status {
	margin-top: 12px;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.license-status__row {
	display: flex;
	gap: 8px;
	padding: 4px 0;
}

.license-status__label {
	font-weight: 500;
	min-width: 100px;
	color: var(--color-text-maxcontrast);
}

.license-status__value--valid {
	color: var(--color-success-text);
	font-weight: 600;
}

.license-status__value--expired,
.license-status__value--none {
	color: var(--color-error-text);
	font-weight: 600;
}

.license-status__value--warning {
	color: var(--color-warning);
	font-weight: 600;
}

.license-status__value--error {
	color: var(--color-error-text);
	font-weight: 600;
}
</style>
