<template>
	<form class="rooms-group-editor" @submit.prevent="onSave">
		<h4>{{ isCreate ? t('sendentsynchroniser', 'New group') : t('sendentsynchroniser', 'Edit group') }}</h4>

		<!-- ID hidden on create (auto-slugified from name); shown read-only on edit. -->
		<div v-if="!isCreate" class="rooms-group-editor__field">
			<label class="rooms-group-editor__label" for="rooms-group-editor-id">
				{{ t('sendentsynchroniser', 'ID') }}
			</label>
			<input id="rooms-group-editor-id" v-model.trim="form.id" type="text" disabled>
		</div>
		<div class="rooms-group-editor__field">
			<label class="rooms-group-editor__label" for="rooms-group-editor-name">
				{{ t('sendentsynchroniser', 'Name') }}
			</label>
			<input id="rooms-group-editor-name" v-model.trim="form.name" type="text" required>
		</div>
		<div class="rooms-group-editor__field">
			<label class="rooms-group-editor__label" for="rooms-group-editor-description">
				{{ t('sendentsynchroniser', 'Description') }}
			</label>
			<textarea id="rooms-group-editor-description" v-model="form.description" rows="2"></textarea>
		</div>

		<p v-if="error" class="rooms-group-editor__error">{{ error }}</p>
		<div class="rooms-group-editor__actions">
			<button type="submit">{{ isCreate ? t('sendentsynchroniser', 'Create') : t('sendentsynchroniser', 'Save') }}</button>
			<button type="button" @click="$emit('cancel')">{{ t('sendentsynchroniser', 'Cancel') }}</button>
		</div>
	</form>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useRoomGroupsStore } from '../stores/roomGroups'
import type { RoomGroupDto } from '../services/roomsApi'

const props = defineProps<{ group: RoomGroupDto | null }>()
const emit = defineEmits<{ (e: 'saved' | 'cancel'): void }>()

const store = useRoomGroupsStore()
const isCreate = computed(() => props.group === null)

const form = reactive<{ id: string; name: string; description: string | null }>({
	id: '', name: '', description: null,
})
const error = ref<string | null>(null)

watch(() => props.group, (g) => {
	form.id = g?.id ?? ''
	form.name = g?.name ?? ''
	form.description = g?.description ?? null
}, { immediate: true })

async function onSave(): Promise<void> {
	error.value = null
	try {
		if (isCreate.value) {
			const id = slugify(form.name)
			if (id.length < 2) {
				error.value = t('sendentsynchroniser', 'Please enter a longer name (the group id is derived from it).')
				return
			}
			await store.create({ id, name: form.name, description: form.description })
		} else {
			await store.update(props.group!.id, { name: form.name, description: form.description })
		}
		emit('saved')
	} catch (e) {
		error.value = extractMessage(e)
	}
}

function slugify(name: string): string {
	return name
		.toLowerCase()
		.normalize('NFKD')
		.replace(/[̀-ͯ]/g, '')
		.replace(/[^a-z0-9-]+/g, '-')
		.replace(/-{2,}/g, '-')
		.replace(/^-+|-+$/g, '')
		.substring(0, 64)
		.replace(/-+$/g, '')
}

function extractMessage(e: unknown): string {
	if (typeof e === 'object' && e !== null && 'response' in e) {
		const resp = (e as { response?: { data?: { error?: { message?: string } } } }).response
		return resp?.data?.error?.message ?? 'Request failed'
	}
	return e instanceof Error ? e.message : 'Request failed'
}
</script>

<style scoped>
.rooms-group-editor {
	display: flex !important;
	flex-direction: column !important;
	gap: 12px;
	padding: 16px;
	border: 1px solid var(--color-border, #ccc);
	border-radius: 6px;
	max-width: 480px;
}
.rooms-group-editor h4 { margin: 0; }
.rooms-group-editor__field { display: block !important; }
.rooms-group-editor__label {
	display: block !important;
	font-size: 13px;
	font-weight: 500;
	margin: 0 0 4px 0 !important;
}
.rooms-group-editor__field > input,
.rooms-group-editor__field > textarea {
	display: block !important;
	width: 100% !important;
	box-sizing: border-box !important;
	padding: 6px 8px !important;
	margin: 0 !important;
}
.rooms-group-editor__error { color: #c62828; margin: 0; }
.rooms-group-editor__actions { display: flex !important; gap: 8px; flex-wrap: wrap; }
</style>
