<template>
	<form class="rooms-editor" @submit.prevent="onSave">
		<h3>
			{{ titleLabel }}
			<button v-if="isView"
				type="button"
				class="rooms-editor__edit-toggle"
				@click="$emit('switch-to-edit')">
				{{ t('sendentsynchroniser', 'Edit') }}
			</button>
		</h3>

		<div class="rooms-editor__grid">
			<!-- Internal ID: hidden on create (auto-slugified from name); shown read-only on edit. -->
			<div v-if="!isCreate" class="rooms-editor__field">
				<label class="rooms-editor__label" for="rooms-editor-id">
					{{ t('sendentsynchroniser', 'ID') }}
				</label>
				<input id="rooms-editor-id"
					v-model.trim="form.id"
					type="text"
					disabled>
			</div>
			<div class="rooms-editor__field">
				<label class="rooms-editor__label" for="rooms-editor-name">
					<span class="rooms-editor__field-label">
						{{ t('sendentsynchroniser', 'Name') }}
						<span v-if="lockedFields.has('name')"
							class="rooms-editor__synced-badge"
							:title="syncedTooltip">
							{{ t('sendentsynchroniser', 'Synced') }}
						</span>
					</span>
				</label>
				<input id="rooms-editor-name"
					v-model.trim="form.name"
					type="text"
					required
					:disabled="inputDisabled('name')"
					:title="lockedFields.has('name') ? syncedTooltip : undefined">
			</div>
			<div class="rooms-editor__field">
				<label class="rooms-editor__label" for="rooms-editor-email">
					<span class="rooms-editor__field-label">
						{{ t('sendentsynchroniser', 'Email') }}
						<span v-if="lockedFields.has('email')"
							class="rooms-editor__synced-badge"
							:title="syncedTooltip">
							{{ t('sendentsynchroniser', 'Synced') }}
						</span>
					</span>
				</label>
				<input id="rooms-editor-email"
					v-model.trim="form.email"
					type="email"
					:disabled="inputDisabled('email')"
					:title="lockedFields.has('email') ? syncedTooltip : undefined">
			</div>
			<div class="rooms-editor__field">
				<label class="rooms-editor__label" for="rooms-editor-capacity">
					<span class="rooms-editor__field-label">
						{{ t('sendentsynchroniser', 'Capacity') }}
						<span v-if="lockedFields.has('capacity')"
							class="rooms-editor__synced-badge"
							:title="syncedTooltip">
							{{ t('sendentsynchroniser', 'Synced') }}
						</span>
					</span>
				</label>
				<input id="rooms-editor-capacity"
					v-model.number="form.capacity"
					type="number"
					min="0"
					:disabled="inputDisabled('capacity')"
					:title="lockedFields.has('capacity') ? syncedTooltip : undefined">
			</div>
			<div class="rooms-editor__field">
				<label class="rooms-editor__label" for="rooms-editor-roomNumber">
					{{ t('sendentsynchroniser', 'Room number') }}
				</label>
				<input id="rooms-editor-roomNumber"
					v-model.trim="form.roomNumber"
					type="text"
					:disabled="isView">
			</div>
			<div class="rooms-editor__field">
				<label class="rooms-editor__label" for="rooms-editor-floor">
					{{ t('sendentsynchroniser', 'Floor') }}
				</label>
				<input id="rooms-editor-floor"
					v-model.trim="form.floor"
					type="text"
					:disabled="isView">
			</div>
			<div class="rooms-editor__field">
				<label class="rooms-editor__label" for="rooms-editor-address">
					{{ t('sendentsynchroniser', 'Address') }}
				</label>
				<input id="rooms-editor-address"
					v-model.trim="form.address"
					type="text"
					:disabled="isView">
			</div>
			<div class="rooms-editor__field">
				<label class="rooms-editor__label" for="rooms-editor-roomType">
					{{ t('sendentsynchroniser', 'Type') }}
				</label>
				<select id="rooms-editor-roomType" v-model="form.roomType" :disabled="isView">
					<option value="meeting-room">
						meeting-room
					</option>
					<option value="board-room">
						board-room
					</option>
					<option value="phone-booth">
						phone-booth
					</option>
					<option value="office">
						office
					</option>
				</select>
			</div>
			<div class="rooms-editor__field">
				<label class="rooms-editor__label" for="rooms-editor-group">
					{{ t('sendentsynchroniser', 'Group') }}
				</label>
				<select id="rooms-editor-group" v-model="form.groupId" :disabled="isView">
					<option :value="null">
						—
					</option>
					<option v-for="g in groupsStore.groups" :key="g.id" :value="g.id">
						{{ g.name }}
					</option>
				</select>
			</div>
			<div class="rooms-editor__field rooms-editor__field--checkbox">
				<input id="rooms-editor-active"
					v-model="form.active"
					type="checkbox"
					:disabled="isView">
				<label class="rooms-editor__label rooms-editor__label--inline" for="rooms-editor-active">
					{{ t('sendentsynchroniser', 'Active') }}
				</label>
			</div>
		</div>

		<div class="rooms-editor__field rooms-editor__field--full">
			<label class="rooms-editor__label" for="rooms-editor-description">
				{{ t('sendentsynchroniser', 'Description') }}
			</label>
			<textarea id="rooms-editor-description"
				v-model="form.description"
				rows="3"
				:disabled="isView" />
		</div>

		<div v-if="!isView" class="rooms-editor__field rooms-editor__field--full">
			<span class="rooms-editor__label">{{ t('sendentsynchroniser', 'Facilities') }}</span>
			<RoomFacilitiesInput v-model="facilities" />
		</div>
		<div v-else-if="facilities.length > 0" class="rooms-editor__field rooms-editor__field--full">
			<span class="rooms-editor__label">{{ t('sendentsynchroniser', 'Facilities') }}</span>
			<div class="rooms-editor__facilities-readonly">
				<span v-for="f in facilities" :key="f" class="rooms-editor__facility-chip">{{ f }}</span>
			</div>
		</div>

		<RoomBindingSection v-if="!isCreate"
			:binding="props.room?.binding ?? null"
			:licensed="licensed" />

		<RoomPermissionEditor v-if="!isCreate && !isView" :room-id="props.room!.id" />

		<p v-if="error" class="rooms-editor__error">
			{{ error }}
		</p>

		<div class="rooms-editor__actions">
			<button v-if="!isView"
				type="submit"
				:disabled="saving">
				{{ isCreate ? t('sendentsynchroniser', 'Create') : t('sendentsynchroniser', 'Save') }}
			</button>
			<button type="button" @click="$emit('cancel')">
				{{ isView ? t('sendentsynchroniser', 'Close') : t('sendentsynchroniser', 'Cancel') }}
			</button>
			<button v-if="!isCreate && !isView"
				type="button"
				class="rooms-editor__btn-danger"
				@click="onDelete">
				{{ t('sendentsynchroniser', 'Delete') }}
			</button>
		</div>
	</form>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import RoomFacilitiesInput from './RoomFacilitiesInput.vue'
import RoomBindingSection from './RoomBindingSection.vue'
import RoomPermissionEditor from './RoomPermissionEditor.vue'
import { useRoomsStore } from '../stores/rooms'
import { useRoomGroupsStore } from '../stores/roomGroups'
import type { RoomDto } from '../services/roomsApi'

const props = withDefaults(
	defineProps<{ room: RoomDto | null; licensed: boolean; mode?: 'edit' | 'view' }>(),
	{ mode: 'edit' },
)
const emit = defineEmits<{
	(e: 'saved' | 'cancel' | 'deleted'): void
	(e: 'switch-to-edit'): void
}>()

const store = useRoomsStore()
const groupsStore = useRoomGroupsStore()

const isCreate = computed(() => props.room === null)
const isView = computed(() => props.mode === 'view' && !isCreate.value)

const titleLabel = computed(() => {
	if (isCreate.value) return t('sendentsynchroniser', 'New room')
	if (isView.value) return t('sendentsynchroniser', 'Room details')
	return t('sendentsynchroniser', 'Edit room')
})

// Lock everything in view mode; otherwise apply the binding-aware lock for
// Exchange-owned fields (name/email/capacity).
/**
 *
 * @param field
 */
function inputDisabled(field: string): boolean {
	return isView.value || lockedFields.value.has(field)
}

// Read-only-when-bound: Exchange owns name/email/capacity once a binding exists.
// Rule is on `binding !== null`, not on state — see plan §Task 7 rationale.
const LOCKED_WHEN_BOUND = ['name', 'email', 'capacity'] as const
const lockedFields = computed<Set<string>>(() =>
	props.room?.binding ? new Set<string>(LOCKED_WHEN_BOUND) : new Set<string>(),
)
const syncedTooltip = t(
	'sendentsynchroniser',
	'Managed by the NC Exchange Connector. Edit this in Exchange.',
)

const form = reactive<{
	id: string
	name: string
	email: string | null
	capacity: number | null
	roomNumber: string | null
	floor: string | null
	address: string | null
	roomType: string
	description: string | null
	groupId: string | null
	active: boolean
}>({
	id: '',
	name: '',
	email: null,
	capacity: null,
	roomNumber: null,
	floor: null,
	address: null,
	roomType: 'meeting-room',
	description: null,
	groupId: null,
	active: true,
})

const facilities = ref<string[]>([])
const saving = ref(false)
const error = ref<string | null>(null)

watch(() => props.room, (r) => {
	if (r) {
		form.id = r.id
		form.name = r.name
		form.email = r.email
		form.capacity = r.capacity
		form.roomNumber = r.roomNumber
		form.floor = r.floor
		form.address = r.address
		form.roomType = r.roomType
		form.description = r.description
		form.groupId = r.groupId
		form.active = r.active
		facilities.value = r.facilities ?? []
	}
}, { immediate: true })

/**
 *
 */
async function onSave(): Promise<void> {
	saving.value = true
	error.value = null
	try {
		// On create: auto-generate id from name (kebab-case, alphanumeric only).
		// Backend regex: ^[a-z0-9][a-z0-9-]{0,62}[a-z0-9]$
		if (isCreate.value) {
			const id = slugify(form.name)
			if (id.length < 2) {
				error.value = t('sendentsynchroniser', 'Please enter a longer name (the room id is derived from it).')
				saving.value = false
				return
			}
			form.id = id
		}

		const payload: Record<string, unknown> = { ...form, facilities: facilities.value }
		// Defense in depth: even if a disabled input were re-enabled via devtools,
		// don't PATCH a field Exchange owns.
		for (const f of lockedFields.value) {
			delete payload[f]
		}
		if (isCreate.value) {
			await store.create(payload)
		} else {
			await store.update(props.room!.id, payload)
		}
		emit('saved')
	} catch (e) {
		error.value = extractMessage(e)
	} finally {
		saving.value = false
	}
}

/**
 *
 * @param name
 */
function slugify(name: string): string {
	return name
		.toLowerCase()
		.normalize('NFKD')
		.replace(/[̀-ͯ]/g, '') // strip combining marks (accents → bare letters)
		.replace(/[^a-z0-9-]+/g, '-') // non-alphanumeric → hyphen
		.replace(/-{2,}/g, '-') // collapse runs of hyphens
		.replace(/^-+|-+$/g, '') // trim leading/trailing hyphens
		.substring(0, 64)
		.replace(/-+$/g, '') // re-trim after substring may have left a trailing hyphen
}

/**
 *
 */
async function onDelete(): Promise<void> {
	if (!props.room) return
	if (!confirm(t('sendentsynchroniser', 'Delete room "{name}"?', { name: props.room.name }))) return
	error.value = null
	try {
		await store.remove(props.room.id)
		emit('deleted')
	} catch (e) {
		error.value = extractMessage(e)
	}
}

/**
 *
 * @param e
 */
function extractMessage(e: unknown): string {
	if (typeof e === 'object' && e !== null && 'response' in e) {
		const resp = (e as { response?: { data?: { error?: { message?: string } } } }).response
		return resp?.data?.error?.message ?? 'Request failed'
	}
	return e instanceof Error ? e.message : 'Request failed'
}
</script>

<style scoped>
/* NC's settings page applies aggressive global styles to label/input that flip
   labels to inline-block and force input widths. We use a `<div class="field">`
   wrapper with explicit block layout instead of relying on flex-on-label, and
   pin the layout-critical bits with `!important` so NC's globals can't win. */
.rooms-editor {
	display: flex !important;
	flex-direction: column !important;
	gap: 16px;
	padding: 16px;
	border: 1px solid var(--color-border, #ccc);
	border-radius: 6px;
}
.rooms-editor h3 { margin: 0; }

.rooms-editor__grid {
	display: grid !important;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.rooms-editor__field {
	display: block !important;
	min-width: 0;
}
.rooms-editor__field--full {
	grid-column: 1 / -1;
}
.rooms-editor__field--checkbox {
	display: flex !important;
	align-items: center;
	gap: 8px;
}
.rooms-editor__field--checkbox > input[type="checkbox"] {
	width: auto !important;
	margin: 0 !important;
}

.rooms-editor__label {
	display: block !important;
	font-size: 13px;
	font-weight: 500;
	margin: 0 0 4px 0 !important;
}
.rooms-editor__label--inline {
	display: inline-block !important;
	margin: 0 !important;
}

.rooms-editor__field > input,
.rooms-editor__field > select,
.rooms-editor__field > textarea {
	display: block !important;
	width: 100% !important;
	box-sizing: border-box !important;
	padding: 6px 8px !important;
	margin: 0 !important;
}

.rooms-editor__field-label {
	display: inline-flex;
	align-items: center;
	gap: 6px;
}
.rooms-editor__synced-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 8px;
	font-size: 11px;
	font-weight: 500;
	background: #1976d2;
	color: #fff;
	cursor: help;
}

.rooms-editor__error { color: #c62828; margin: 0; }
.rooms-editor__actions {
	display: flex !important;
	gap: 8px;
	flex-wrap: wrap;
}
.rooms-editor__btn-danger { background: #c62828; color: #fff; margin-left: auto; }

@media (max-width: 720px) {
	.rooms-editor__grid {
		grid-template-columns: 1fr;
	}
}

.rooms-editor__edit-toggle {
	margin-left: 12px;
	padding: 4px 12px !important;
	font-size: 13px;
	background: var(--color-primary, #1976d2) !important;
	color: #fff !important;
}

.rooms-editor__facilities-readonly {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}
.rooms-editor__facility-chip {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 12px;
	background: var(--color-background-darker, #ddd);
	font-size: 13px;
}
</style>
