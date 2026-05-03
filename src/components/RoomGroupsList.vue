<template>
	<div class="rooms-groups">
		<div class="rooms-groups__toolbar">
			<input v-if="!editing"
				v-model="filter"
				type="text"
				class="rooms-groups__filter"
				:placeholder="t('sendentsynchroniser', 'Filter groups…')">
			<button v-if="!editing" type="button" @click="onCreate">
				+ {{ t('sendentsynchroniser', 'New group') }}
			</button>
		</div>

		<RoomGroupEditor v-if="editing"
			:group="editTarget"
			@saved="onSaved"
			@cancel="editing = false" />

		<table v-else-if="store.groups.length > 0" class="rooms-groups__table">
			<thead>
				<tr>
					<th>{{ t('sendentsynchroniser', 'Name') }}</th>
					<th>{{ t('sendentsynchroniser', 'ID') }}</th>
					<th>{{ t('sendentsynchroniser', 'Description') }}</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="g in store.groups" :key="g.id">
					<td>{{ g.name }}</td>
					<td><code>{{ g.id }}</code></td>
					<td>{{ g.description ?? '—' }}</td>
					<td>
						<button type="button" @click="onEdit(g)">{{ t('sendentsynchroniser', 'Edit') }}</button>
						<button type="button" class="rooms-groups__btn-danger" @click="onDelete(g.id, g.name)">×</button>
					</td>
				</tr>
			</tbody>
		</table>
		<p v-else-if="!editing && !store.loading" class="rooms-groups__empty">
			{{ store.q.trim() === ''
				? t('sendentsynchroniser', 'No room groups yet.')
				: t('sendentsynchroniser', 'No groups match your search.') }}
		</p>

		<Pagination v-if="!editing"
			:page="store.page"
			:per-page="store.perPage"
			:total="store.total"
			@update:page="store.setPage"
			@update:per-page="store.setPerPage" />

		<p v-if="error" class="rooms-groups__error">{{ error }}</p>
	</div>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import RoomGroupEditor from './RoomGroupEditor.vue'
import Pagination from './Pagination.vue'
import { useRoomGroupsStore } from '../stores/roomGroups'
import type { RoomGroupDto } from '../services/roomsApi'

const store = useRoomGroupsStore()
const editing = ref(false)
const editTarget = ref<RoomGroupDto | null>(null)
const error = ref<string | null>(null)
const filter = ref(store.q)

let debounceHandle: ReturnType<typeof setTimeout> | null = null
watch(filter, (v) => {
	if (debounceHandle !== null) clearTimeout(debounceHandle)
	debounceHandle = setTimeout(() => {
		store.setQuery(v)
	}, 300)
})

function onCreate(): void { editTarget.value = null; editing.value = true }
function onEdit(g: RoomGroupDto): void { editTarget.value = g; editing.value = true }
function onSaved(): void { editing.value = false }

async function onDelete(id: string, name: string): Promise<void> {
	if (!confirm(t('sendentsynchroniser', 'Delete group "{name}"? Rooms will be unassigned.', { name }))) return
	try { await store.remove(id) } catch (e) { error.value = e instanceof Error ? e.message : 'Failed' }
}

onMounted(() => store.refresh())
</script>

<style scoped>
.rooms-groups__toolbar { display: flex; gap: 8px; margin-bottom: 12px; }
.rooms-groups__filter { flex: 1; padding: 6px 8px; }
.rooms-groups__table { width: 100%; border-collapse: collapse; }
.rooms-groups__table th, .rooms-groups__table td { padding: 6px 10px; text-align: left; border-bottom: 1px solid var(--color-border, #eee); }
.rooms-groups__btn-danger { background: #c62828; color: #fff; border: none; padding: 2px 8px; cursor: pointer; margin-left: 4px; }
.rooms-groups__empty { color: var(--color-text-maxcontrast, #777); }
.rooms-groups__error { color: #c62828; }
</style>
