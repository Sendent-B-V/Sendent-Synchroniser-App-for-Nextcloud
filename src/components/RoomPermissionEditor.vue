<template>
	<fieldset class="rooms-perms">
		<legend>{{ t('sendentsynchroniser', 'Permissions') }}</legend>

		<table v-if="permissions.length > 0" class="rooms-perms__table">
			<thead>
				<tr>
					<th>{{ t('sendentsynchroniser', 'Role') }}</th>
					<th>{{ t('sendentsynchroniser', 'Type') }}</th>
					<th>{{ t('sendentsynchroniser', 'Principal') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="p in permissions" :key="p.id">
					<td>{{ p.role }}</td>
					<td>{{ p.principalType }}</td>
					<td>{{ p.principalId }}</td>
					<td>
						<button type="button"
							class="rooms-perms__btn-danger"
							@click="onRevoke(p.id)">
							×
						</button>
					</td>
				</tr>
			</tbody>
		</table>
		<p v-else class="rooms-perms__empty">
			{{ t('sendentsynchroniser', 'No permissions granted yet.') }}
		</p>

		<div class="rooms-perms__form">
			<select v-model="newRole">
				<option value="viewer">
					viewer
				</option>
				<option value="booker">
					booker
				</option>
				<option value="manager">
					manager
				</option>
			</select>
			<select v-model="newPrincipalType">
				<option value="user">
					user
				</option>
				<option value="group">
					group
				</option>
			</select>
			<input v-model="newPrincipalId"
				type="text"
				:placeholder="t('sendentsynchroniser', 'username or group id')">
			<button type="button" :disabled="!canGrant" @click="onGrant">
				{{ t('sendentsynchroniser', 'Grant') }}
			</button>
		</div>
		<p v-if="error" class="rooms-perms__error">
			{{ error }}
		</p>
	</fieldset>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useRoomsStore } from '../stores/rooms'
import type { RoomPermissionDto } from '../services/roomsApi'

const props = defineProps<{ roomId: string }>()
const store = useRoomsStore()

const permissions = ref<RoomPermissionDto[]>([])
const error = ref<string | null>(null)

const newRole = ref<RoomPermissionDto['role']>('booker')
const newPrincipalType = ref<RoomPermissionDto['principalType']>('user')
const newPrincipalId = ref('')

const canGrant = computed(() => newPrincipalId.value.trim() !== '')

/**
 *
 */
async function reload(): Promise<void> {
	error.value = null
	try {
		permissions.value = await store.listPermissions(props.roomId)
	} catch (e) {
		error.value = extractMessage(e)
	}
}

/**
 *
 */
async function onGrant(): Promise<void> {
	error.value = null
	try {
		const created = await store.grantPermission(props.roomId, newRole.value, newPrincipalType.value, newPrincipalId.value.trim())
		permissions.value.push(created)
		newPrincipalId.value = ''
	} catch (e) {
		error.value = extractMessage(e)
	}
}

/**
 *
 * @param permId
 */
async function onRevoke(permId: number): Promise<void> {
	error.value = null
	try {
		await store.revokePermission(props.roomId, permId)
		permissions.value = permissions.value.filter(p => p.id !== permId)
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

onMounted(reload)
watch(() => props.roomId, reload)
</script>

<style scoped>
.rooms-perms { border: 1px solid var(--color-border, #ccc); padding: 12px 16px; border-radius: 6px; margin-top: 12px; }
.rooms-perms__table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
.rooms-perms__table th, .rooms-perms__table td { padding: 4px 8px; text-align: left; border-bottom: 1px solid var(--color-border, #eee); }
.rooms-perms__empty { color: var(--color-text-maxcontrast, #777); font-style: italic; }
.rooms-perms__form { display: flex; gap: 6px; align-items: center; margin-top: 8px; }
.rooms-perms__form input, .rooms-perms__form select { padding: 4px 6px; }
.rooms-perms__form input { flex: 1; }
.rooms-perms__btn-danger { background: #c62828; color: #fff; border: none; padding: 2px 6px; cursor: pointer; }
.rooms-perms__error { color: #c62828; margin-top: 6px; }
</style>
