import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export interface GroupItem {
	displayName: string
	gid: string
}

export const useGroupsStore = defineStore('groups', () => {
	const ncGroups = ref<GroupItem[]>([])
	const sendentGroups = ref<GroupItem[]>([])
	const selectedGroupId = ref<string | null>(null)

	const selectedGroup = computed<GroupItem | null>(() => {
		if (selectedGroupId.value === null) return null
		return sendentGroups.value.find(g => g.gid === selectedGroupId.value) ?? null
	})

	function loadInitialState() {
		try {
			const state = loadState('sendentsynchroniser', 'admin') as Record<string, any>
			ncGroups.value = state.ncGroups || []
			sendentGroups.value = state.sendentGroups || []
		} catch {
			ncGroups.value = []
			sendentGroups.value = []
		}
	}

	function addGroup(gid: string) {
		const index = ncGroups.value.findIndex(g => g.gid === gid)
		if (index === -1) return
		const [group] = ncGroups.value.splice(index, 1)
		sendentGroups.value.push(group)
		syncToBackend()
	}

	function removeGroup(gid: string) {
		const index = sendentGroups.value.findIndex(g => g.gid === gid)
		if (index === -1) return
		const [group] = sendentGroups.value.splice(index, 1)
		if (!group.displayName.includes('*** DELETED GROUP ***')) {
			ncGroups.value.push(group)
			ncGroups.value.sort((a, b) => a.gid.localeCompare(b.gid))
		}
		// If removed group was selected, deselect
		if (selectedGroupId.value === gid) {
			selectedGroupId.value = sendentGroups.value.length > 0 ? sendentGroups.value[0].gid : null
		}
		syncToBackend()
	}

	function reorderGroups(newOrder: GroupItem[]) {
		sendentGroups.value = newOrder
		syncToBackend()
	}

	function selectGroup(gid: string) {
		selectedGroupId.value = gid
	}

	async function syncToBackend() {
		const newSendentGroups = sendentGroups.value.map(g => g.gid)
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/activeGroups')
		try {
			await axios.post(url, { newSendentGroups })
		} catch (err) {
			console.error('Failed to sync groups to backend', err)
		}
	}

	return {
		ncGroups,
		sendentGroups,
		selectedGroupId,
		selectedGroup,
		loadInitialState,
		addGroup,
		removeGroup,
		reorderGroups,
		selectGroup,
	}
})
