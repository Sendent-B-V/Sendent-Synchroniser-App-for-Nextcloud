import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import * as api from '../services/roomsApi'
import type { RoomDto, RoomBindingDto, RoomPermissionDto } from '../services/roomsApi'

export const useRoomsStore = defineStore('rooms', () => {
	const rooms = ref<RoomDto[]>([])
	const loading = ref(false)
	const error = ref<string | null>(null)
	const selectedRoomId = ref<string | null>(null)

	const page = ref(1)
	const perPage = ref(30)
	const q = ref('')
	const total = ref(0)

	const selectedRoom = computed<RoomDto | null>(() => {
		if (selectedRoomId.value === null) return null
		return rooms.value.find(r => r.id === selectedRoomId.value) ?? null
	})

	/**
	 *
	 */
	async function refresh(): Promise<void> {
		loading.value = true
		error.value = null
		try {
			const resp = await api.listRooms({
				page: page.value,
				perPage: perPage.value,
				q: q.value.trim() === '' ? undefined : q.value.trim(),
			})
			rooms.value = resp.data.items
			page.value = resp.data.page
			total.value = resp.data.total
		} catch (e) {
			error.value = extractError(e)
		} finally {
			loading.value = false
		}
	}

	/**
	 *
	 * @param p
	 */
	function setPage(p: number): void {
		page.value = p
		refresh()
	}

	/**
	 *
	 * @param n
	 */
	function setPerPage(n: number): void {
		perPage.value = n
		page.value = 1
		refresh()
	}

	/**
	 *
	 * @param s
	 */
	function setQuery(s: string): void {
		q.value = s
		page.value = 1
		refresh()
	}

	/**
	 *
	 * @param data
	 */
	async function create(data: Partial<RoomDto>): Promise<RoomDto> {
		const resp = await api.createRoom(data)
		await refresh()
		return resp.data
	}

	/**
	 *
	 * @param id
	 * @param patch
	 */
	async function update(id: string, patch: Partial<RoomDto>): Promise<RoomDto> {
		const resp = await api.updateRoom(id, patch)
		await refresh()
		return resp.data
	}

	/**
	 *
	 * @param id
	 */
	async function remove(id: string): Promise<void> {
		await api.deleteRoom(id)
		if (selectedRoomId.value === id) selectedRoomId.value = null
		await refresh()
	}

	/**
	 *
	 * @param id
	 * @param kind
	 * @param externalId
	 * @param config
	 */
	async function setBinding(id: string, kind: string, externalId: string, config: Record<string, unknown> = {}): Promise<RoomBindingDto> {
		const resp = await api.putBinding(id, kind, externalId, config)
		applyBinding(id, resp.data)
		return resp.data
	}

	/**
	 *
	 * @param id
	 */
	async function clearBinding(id: string): Promise<void> {
		await api.deleteBinding(id)
		applyBinding(id, null)
	}

	/**
	 *
	 * @param id
	 */
	async function retryBinding(id: string): Promise<RoomBindingDto> {
		const resp = await api.retryBinding(id)
		applyBinding(id, resp.data)
		return resp.data
	}

	/**
	 *
	 * @param roomId
	 * @param binding
	 */
	function applyBinding(roomId: string, binding: RoomBindingDto | null): void {
		const idx = rooms.value.findIndex(r => r.id === roomId)
		if (idx >= 0) rooms.value[idx] = { ...rooms.value[idx], binding }
	}

	/**
	 *
	 * @param id
	 */
	function selectRoom(id: string | null): void {
		selectedRoomId.value = id
	}

	/**
	 *
	 * @param roomId
	 */
	async function listPermissions(roomId: string): Promise<RoomPermissionDto[]> {
		const resp = await api.listPermissions(roomId)
		return resp.data.permissions
	}

	/**
	 *
	 * @param roomId
	 * @param role
	 * @param principalType
	 * @param principalId
	 */
	async function grantPermission(
		roomId: string,
		role: RoomPermissionDto['role'],
		principalType: RoomPermissionDto['principalType'],
		principalId: string,
	): Promise<RoomPermissionDto> {
		const resp = await api.grantPermission(roomId, role, principalType, principalId)
		return resp.data
	}

	/**
	 *
	 * @param roomId
	 * @param permId
	 */
	async function revokePermission(roomId: string, permId: number): Promise<void> {
		await api.revokePermission(roomId, permId)
	}

	return {
		rooms,
		loading,
		error,
		selectedRoomId,
		selectedRoom,
		page,
		perPage,
		q,
		total,
		refresh,
		setPage,
		setPerPage,
		setQuery,
		create,
		update,
		remove,
		setBinding,
		clearBinding,
		retryBinding,
		selectRoom,
		listPermissions,
		grantPermission,
		revokePermission,
	}
})

/**
 *
 * @param e
 */
function extractError(e: unknown): string {
	if (typeof e === 'object' && e !== null && 'response' in e) {
		const resp = (e as { response?: { data?: { error?: { message?: string } } } }).response
		return resp?.data?.error?.message ?? 'Unknown error'
	}
	return e instanceof Error ? e.message : 'Unknown error'
}
