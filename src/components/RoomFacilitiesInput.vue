<template>
	<div class="rooms-facilities">
		<div class="rooms-facilities__chips">
			<span v-for="(f, i) in modelValue"
				:key="`${f}-${i}`"
				class="rooms-facilities__chip">
				{{ f }}
				<button type="button"
					class="rooms-facilities__remove"
					:title="t('sendentsynchroniser', 'Remove facility')"
					@click="remove(i)">×</button>
			</span>
		</div>
		<input v-model="draft"
			type="text"
			class="rooms-facilities__input"
			:placeholder="t('sendentsynchroniser', 'Add a facility (Enter to confirm)')"
			@keydown.enter.prevent="commit">
	</div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'

const props = defineProps<{ modelValue: string[] }>()
const emit = defineEmits<{ (e: 'update:modelValue', value: string[]): void }>()

const draft = ref('')

/**
 *
 */
function commit(): void {
	const v = draft.value.trim()
	if (v === '') return
	if (props.modelValue.includes(v)) {
		draft.value = ''
		return
	}
	emit('update:modelValue', [...props.modelValue, v])
	draft.value = ''
}

/**
 *
 * @param i
 */
function remove(i: number | string): void {
	const next = [...props.modelValue]
	next.splice(Number(i), 1)
	emit('update:modelValue', next)
}
</script>

<style scoped>
.rooms-facilities__chips {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-bottom: 6px;
}
.rooms-facilities__chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 8px;
	border-radius: 12px;
	background: var(--color-background-darker, #ddd);
	font-size: 13px;
}
.rooms-facilities__remove {
	background: none;
	border: none;
	cursor: pointer;
	font-size: 16px;
	padding: 0 2px;
	line-height: 1;
}
.rooms-facilities__input {
	width: 100%;
	box-sizing: border-box;
}
</style>
