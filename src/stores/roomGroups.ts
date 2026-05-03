import { defineStore } from 'pinia'
import { ref } from 'vue'
import * as api from '../services/roomsApi'
import type { RoomGroupDto } from '../services/roomsApi'

export const useRoomGroupsStore = defineStore('roomGroups', () => {
	const groups = ref<RoomGroupDto[]>([])
	const loading = ref(false)
	const error = ref<string | null>(null)

	const page = ref(1)
	const perPage = ref(30)
	const q = ref('')
	const total = ref(0)

	async function refresh(): Promise<void> {
		loading.value = true
		error.value = null
		try {
			const resp = await api.listGroups({
				page: page.value,
				perPage: perPage.value,
				q: q.value.trim() === '' ? undefined : q.value.trim(),
			})
			groups.value = resp.data.items
			page.value = resp.data.page
			total.value = resp.data.total
		} catch (e) {
			error.value = e instanceof Error ? e.message : 'Failed to load groups'
		} finally {
			loading.value = false
		}
	}

	function setPage(p: number): void {
		page.value = p
		refresh()
	}

	function setPerPage(n: number): void {
		perPage.value = n
		page.value = 1
		refresh()
	}

	function setQuery(s: string): void {
		q.value = s
		page.value = 1
		refresh()
	}

	async function create(data: Partial<RoomGroupDto>): Promise<RoomGroupDto> {
		const resp = await api.createGroup(data)
		await refresh()
		return resp.data
	}

	async function update(id: string, patch: Partial<RoomGroupDto>): Promise<RoomGroupDto> {
		const resp = await api.updateGroup(id, patch)
		await refresh()
		return resp.data
	}

	async function remove(id: string): Promise<void> {
		await api.deleteGroup(id)
		await refresh()
	}

	return { groups, loading, error, page, perPage, q, total, refresh, setPage, setPerPage, setQuery, create, update, remove }
})
