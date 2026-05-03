<template>
	<div class="rooms-management">
		<h3>{{ t('sendentsynchroniser', 'Rooms') }}</h3>
		<nav class="rooms-management__tabs">
			<button type="button"
				:class="['rooms-management__tab', { 'rooms-management__tab--active': tab === 'rooms' }]"
				@click="tab = 'rooms'">
				{{ t('sendentsynchroniser', 'Rooms') }}
			</button>
			<button type="button"
				:class="['rooms-management__tab', { 'rooms-management__tab--active': tab === 'groups' }]"
				@click="tab = 'groups'">
				{{ t('sendentsynchroniser', 'Groups') }}
			</button>
			<button type="button"
				:class="['rooms-management__tab', { 'rooms-management__tab--active': tab === 'bookings' }]"
				@click="tab = 'bookings'">
				{{ t('sendentsynchroniser', 'Bookings') }}
			</button>
		</nav>

		<section v-if="tab === 'rooms'">
			<RoomList v-if="!editorOpen"
				@view="onView"
				@edit="onEdit"
				@create="onCreate" />
			<RoomEditor v-else
				:room="editTarget"
				:licensed="licensed"
				:mode="editorMode"
				@saved="onAfterEdit"
				@deleted="onAfterEdit"
				@cancel="editorOpen = false"
				@switch-to-edit="editorMode = 'edit'" />
		</section>

		<section v-else-if="tab === 'groups'">
			<RoomGroupsList />
		</section>

		<section v-else-if="tab === 'bookings'">
			<RoomBookingsView />
		</section>
	</div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import RoomList from './RoomList.vue'
import RoomEditor from './RoomEditor.vue'
import RoomGroupsList from './RoomGroupsList.vue'
import RoomBookingsView from './RoomBookingsView.vue'
import { useRoomsStore } from '../stores/rooms'
import { useLicenseStore } from '../stores/license'
import type { RoomDto } from '../services/roomsApi'

const tab = ref<'rooms' | 'groups' | 'bookings'>('rooms')
const editorOpen = ref(false)
const editTarget = ref<RoomDto | null>(null)
const editorMode = ref<'edit' | 'view'>('view')

const store = useRoomsStore()
const licenseStore = useLicenseStore()

// License is "valid" when LicenseStatus.statusKind === 'valid'.
// (Other kinds: 'expired', 'nolicense', 'userlimit', 'check', 'error_incomplete'.)
// See src/components/LicenseStatusDisplay.vue:47-53 for the canonical mapping.
const licensed = computed<boolean>(() => licenseStore.status?.statusKind === 'valid')

function onView(id: string): void {
	editTarget.value = store.rooms.find(r => r.id === id) ?? null
	editorMode.value = 'view'
	editorOpen.value = true
}

function onEdit(id: string): void {
	editTarget.value = store.rooms.find(r => r.id === id) ?? null
	editorMode.value = 'edit'
	editorOpen.value = true
}

function onCreate(): void {
	editTarget.value = null
	editorMode.value = 'edit'
	editorOpen.value = true
}

function onAfterEdit(): void {
	editorOpen.value = false
	store.refresh()
}
</script>

<style scoped>
.rooms-management { margin-top: 32px; }
.rooms-management__tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--color-border, #ccc); margin-bottom: 16px; }
.rooms-management__tab {
	background: none;
	border: none;
	padding: 8px 16px;
	cursor: pointer;
	border-bottom: 2px solid transparent;
}
.rooms-management__tab--active {
	border-bottom-color: var(--color-primary, #1976d2);
	font-weight: 600;
}
</style>
