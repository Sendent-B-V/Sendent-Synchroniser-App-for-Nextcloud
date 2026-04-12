<template>
	<div class="collection-selector">
		<label class="collection-selector__label">{{ label }}</label>
		<div class="collection-selector__input-row">
			<select v-model="selected"
				class="collection-selector__select"
				:disabled="disabled"
				@change="onChange">
				<option v-for="item in collections"
					:key="item.uri"
					:value="item.uri">
					{{ item.displayName }}
				</option>
				<option value="__create__">
					+ Create new...
				</option>
			</select>
			<span v-if="saved" class="collection-selector__saved">&#x2713;</span>
		</div>

		<!-- Inline create form -->
		<div v-if="showCreate" class="collection-selector__create">
			<input v-model="newUri"
				type="text"
				:placeholder="createPlaceholder"
				class="collection-selector__create-input">
			<button class="primary" :disabled="!newUri.trim()" @click="onCreateAndSelect">
				Create
			</button>
			<button @click="showCreate = false">
				Cancel
			</button>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'

interface CollectionItem {
	id: number
	uri: string
	displayName: string
}

const props = defineProps<{
	label: string
	collections: CollectionItem[]
	modelValue: string
	disabled?: boolean
	createPlaceholder?: string
}>()

const emit = defineEmits<{
	'update:modelValue': [value: string]
	'create': [uri: string, displayName: string]
}>()

const selected = ref(props.modelValue)
const showCreate = ref(false)
const newUri = ref('')
const saved = ref(false)

watch(() => props.modelValue, (val) => {
	selected.value = val
})

/**
 *
 */
function onChange() {
	if (selected.value === '__create__') {
		showCreate.value = true
		selected.value = props.modelValue
		return
	}
	emit('update:modelValue', selected.value)
}

/**
 *
 */
function onCreateAndSelect() {
	const uri = newUri.value.trim().toLowerCase().replace(/[^a-z0-9-_]/g, '-')
	const displayName = newUri.value.trim()
	emit('create', uri, displayName)
	newUri.value = ''
	showCreate.value = false
}

/**
 *
 */
function flashSaved() {
	saved.value = true
	setTimeout(() => { saved.value = false }, 1500)
}

defineExpose({ flashSaved })
</script>

<style scoped>
.collection-selector {
	margin-bottom: 12px;
}

.collection-selector__label {
	display: block;
	font-weight: 500;
	margin-bottom: 4px;
}

.collection-selector__input-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.collection-selector__select {
	width: 100%;
	max-width: 400px;
}

.collection-selector__saved {
	color: var(--color-success-text);
	font-weight: 600;
	animation: fadeIn 0.3s;
}

.collection-selector__create {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 8px;
}

.collection-selector__create-input {
	max-width: 300px;
}

@keyframes fadeIn {
	from { opacity: 0; }
	to { opacity: 1; }
}
</style>
