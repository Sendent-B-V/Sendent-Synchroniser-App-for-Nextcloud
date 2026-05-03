<template>
	<div class="rooms-list">
		<div class="rooms-list__toolbar">
			<input v-model="filter"
				type="text"
				class="rooms-list__filter"
				:placeholder="t('sendentsynchroniser', 'Filter rooms…')">
			<button type="button" @click="$emit('create')">
				+ {{ t('sendentsynchroniser', 'New room') }}
			</button>
		</div>

		<p v-if="store.loading" class="rooms-list__status">
			{{ t('sendentsynchroniser', 'Loading…') }}
		</p>
		<p v-else-if="store.error" class="rooms-list__error">
			{{ store.error }}
		</p>

		<table v-if="store.rooms.length > 0" class="rooms-list__table">
			<thead>
				<tr>
					<th>{{ t('sendentsynchroniser', 'Name') }}</th>
					<th>{{ t('sendentsynchroniser', 'ID') }}</th>
					<th>{{ t('sendentsynchroniser', 'Capacity') }}</th>
					<th>{{ t('sendentsynchroniser', 'Group') }}</th>
					<th>{{ t('sendentsynchroniser', 'Binding') }}</th>
					<th>{{ t('sendentsynchroniser', 'Active') }}</th>
					<th class="rooms-list__actions-col">
						{{ t('sendentsynchroniser', 'Actions') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="r in store.rooms"
					:key="r.id"
					class="rooms-list__row"
					@click="$emit('view', r.id)">
					<td>{{ r.name }}</td>
					<td><code>{{ r.id }}</code></td>
					<td>{{ r.capacity ?? '—' }}</td>
					<td>{{ groupName(r.groupId) }}</td>
					<td>
						<span v-if="r.binding"
							class="rooms-list__badge"
							:class="`rooms-list__badge--${r.binding.state}`">
							{{ r.binding.kind }} · {{ r.binding.state }}
						</span>
						<span v-else class="rooms-list__muted">—</span>
					</td>
					<td>{{ r.active ? '✓' : '—' }}</td>
					<td class="rooms-list__actions" @click.stop>
						<button type="button"
							class="rooms-list__action"
							:title="t('sendentsynchroniser', 'View details (read-only)')"
							@click="$emit('view', r.id)">
							{{ t('sendentsynchroniser', 'Details') }}
						</button>
						<button type="button"
							class="rooms-list__action rooms-list__action--primary"
							:title="t('sendentsynchroniser', 'Edit (write mode)')"
							@click="$emit('edit', r.id)">
							{{ t('sendentsynchroniser', 'Edit') }}
						</button>
					</td>
				</tr>
			</tbody>
		</table>
		<p v-else-if="!store.loading" class="rooms-list__empty">
			{{ store.q.trim() === ''
				? t('sendentsynchroniser', 'No rooms yet. Click "New room" to add one.')
				: t('sendentsynchroniser', 'No rooms match your search.') }}
		</p>

		<Pagination :page="store.page"
			:per-page="store.perPage"
			:total="store.total"
			@update:page="store.setPage"
			@update:per-page="store.setPerPage" />
	</div>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useRoomsStore } from '../stores/rooms'
import { useRoomGroupsStore } from '../stores/roomGroups'
import Pagination from './Pagination.vue'

defineEmits<{
	(e: 'view', id: string): void
	(e: 'edit', id: string): void
	(e: 'create'): void
}>()

const store = useRoomsStore()
const groupsStore = useRoomGroupsStore()

const filter = ref(store.q)

let debounceHandle: ReturnType<typeof setTimeout> | null = null
watch(filter, (v) => {
	if (debounceHandle !== null) clearTimeout(debounceHandle)
	debounceHandle = setTimeout(() => {
		store.setQuery(v)
	}, 300)
})

/**
 *
 * @param id
 */
function groupName(id: string | null): string {
	if (id === null) return '—'
	return groupsStore.groups.find(g => g.id === id)?.name ?? id
}

onMounted(() => {
	store.refresh()
	groupsStore.refresh()
})
</script>

<style scoped>
.rooms-list__toolbar { display: flex; gap: 8px; margin-bottom: 12px; }
.rooms-list__filter { flex: 1; padding: 6px 8px; }
.rooms-list__status, .rooms-list__error, .rooms-list__empty { padding: 12px 0; color: var(--color-text-maxcontrast, #555); }
.rooms-list__error { color: #c62828; }
.rooms-list__table { width: 100%; border-collapse: collapse; }
.rooms-list__table th, .rooms-list__table td { padding: 6px 10px; text-align: left; border-bottom: 1px solid var(--color-border, #eee); }
.rooms-list__row { cursor: pointer; }
.rooms-list__row:hover { background: var(--color-background-hover, #f6f6f6); }
.rooms-list__badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; background: var(--color-background-darker, #ddd); }
.rooms-list__badge--pending { background: #888; color: #fff; }
.rooms-list__badge--syncing { background: #1976d2; color: #fff; }
.rooms-list__badge--completed { background: #2e7d32; color: #fff; }
.rooms-list__badge--failed { background: #c62828; color: #fff; }
.rooms-list__badge--idle { background: #aaa; color: #fff; }
.rooms-list__muted { color: var(--color-text-maxcontrast, #999); }
.rooms-list__actions-col { width: 1%; white-space: nowrap; text-align: right; }
.rooms-list__actions {
	white-space: nowrap;
	text-align: right;
}
.rooms-list__action {
	margin-left: 4px;
	padding: 2px 10px !important;
	font-size: 12px;
}
.rooms-list__action--primary {
	background: var(--color-primary, #1976d2) !important;
	color: #fff !important;
}
</style>
