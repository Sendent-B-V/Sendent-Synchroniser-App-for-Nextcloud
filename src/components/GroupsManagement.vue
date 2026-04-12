<template>
	<div class="groups-management">
		<div class="groups-management__panel">
			<h3>Inactive Groups</h3>
			<input v-model="ncFilter"
				type="text"
				placeholder="Filter groups..."
				class="groups-management__filter">
			<div class="groups-management__list">
				<div v-for="group in filteredNcGroups"
					:key="group.gid"
					class="groups-management__item"
					@click="onAddGroup(group.gid)">
					<span>{{ group.displayName }}</span>
					<span class="groups-management__add-icon" title="Add to active groups">&#x2192;</span>
				</div>
				<div v-if="filteredNcGroups.length === 0" class="groups-management__empty">
					No groups found
				</div>
			</div>
		</div>

		<div class="groups-management__panel">
			<h3>Active Groups</h3>
			<input v-model="sendentFilter"
				type="text"
				placeholder="Filter groups..."
				class="groups-management__filter">
			<div ref="sortableRef"
				class="groups-management__list groups-management__list--sortable">
				<div v-for="group in filteredSendentGroups"
					:key="group.gid"
					:data-gid="group.gid"
					class="groups-management__item groups-management__item--sendent"
					:class="{
						'groups-management__item--selected': groupsStore.selectedGroupId === group.gid,
						'groups-management__item--deleted': group.displayName.includes('*** DELETED GROUP ***'),
					}"
					@click="onSelectGroup(group.gid)">
					<span class="groups-management__drag-handle" title="Drag to reorder">&#x2630;</span>
					<span class="groups-management__name">{{ group.displayName }}</span>
					<button class="groups-management__remove"
						title="Remove from active groups"
						@click.stop="onRemoveGroup(group.gid)">
						&times;
					</button>
				</div>
				<div v-if="filteredSendentGroups.length === 0" class="groups-management__empty">
					No active groups
				</div>
			</div>
		</div>
	</div>

	<div class="groups-management__user-info">
		<h3>User management</h3>
		<p>
			{{ t('sendentsynchroniser', 'You have enabled Sendent Sync for {enabled} user(s), and it is currently used by {active} user(s).', { enabled: String(nbEnabledUsers), active: String(nbActiveUsers) }) }}
		</p>
		<p>{{ t('sendentsynchroniser', 'To send a notification to non-active user(s) to remind them to setup their synchronisation, click the "Remind users" button below.') }}</p>
		<p>{{ t('sendentsynchroniser', 'To clear the synchronisation token of active users, and force them to re-generate one, click the "Clear tokens" button below.') }}</p>
		<div class="groups-management__actions">
			<button :disabled="!notificationsAppInstalled"
				@click="onRemindUsers">
				Remind users
			</button>
			<span v-if="!notificationsAppInstalled" class="groups-management__warning">
				{{ t('sendentsynchroniser', "You don't have the notifications app installed") }}
			</span>
			<button class="groups-management__btn-warning"
				@click="onClearTokens">
				Clear tokens
			</button>
		</div>
	</div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { useGroupsStore } from '../stores/groups'
import { useLicenseStore } from '../stores/license'
import { useSortable } from '../composables/useSortable'

const props = defineProps<{
	nbEnabledUsers: number
	nbActiveUsers: number
	notificationsAppInstalled: boolean
}>()

const groupsStore = useGroupsStore()
const licenseStore = useLicenseStore()

const ncFilter = ref('')
const sendentFilter = ref('')
const sortableRef = ref<HTMLElement | null>(null)

const filteredNcGroups = computed(() => {
	const filter = ncFilter.value.toLowerCase()
	if (!filter) return groupsStore.ncGroups
	return groupsStore.ncGroups.filter(g =>
		g.displayName.toLowerCase().includes(filter) || g.gid.toLowerCase().includes(filter),
	)
})

const filteredSendentGroups = computed(() => {
	const filter = sendentFilter.value.toLowerCase()
	if (!filter) return groupsStore.sendentGroups
	return groupsStore.sendentGroups.filter(g =>
		g.displayName.toLowerCase().includes(filter) || g.gid.toLowerCase().includes(filter),
	)
})

useSortable(sortableRef, {
	handle: '.groups-management__drag-handle',
	onEnd(oldIndex, newIndex) {
		const adjusted = [...groupsStore.sendentGroups]
		const [moved] = adjusted.splice(oldIndex, 1)
		adjusted.splice(newIndex, 0, moved)
		groupsStore.reorderGroups(adjusted)
	},
})

function onAddGroup(gid: string) {
	groupsStore.addGroup(gid)
}

function onRemoveGroup(gid: string) {
	groupsStore.removeGroup(gid)
}

async function onSelectGroup(gid: string) {
	groupsStore.selectGroup(gid)
	await licenseStore.refreshStatus()
}

async function onRemindUsers() {
	const url = generateUrl('/apps/sendentsynchroniser/api/1.0/settings/sendReminder')
	try {
		await axios.get(url)
	} catch (err) {
		console.error('Failed to send reminders', err)
	}
}

async function onClearTokens() {
	if (confirm('This will clear the synchronisation token of all sendent sync users. Are you sure?')) {
		const url = generateUrl('/apps/sendentsynchroniser/api/1.0/user/invalidateAll')
		try {
			await axios.post(url)
		} catch (err) {
			console.error('Failed to clear tokens', err)
		}
	}
}

onMounted(() => {
	// Select first active group if any
	if (groupsStore.sendentGroups.length > 0 && groupsStore.selectedGroupId === null) {
		onSelectGroup(groupsStore.sendentGroups[0].gid)
	}
})
</script>

<style scoped>
.groups-management {
	display: flex;
	gap: 20px;
	margin-bottom: 24px;
}

.groups-management__panel {
	flex: 1;
	min-width: 250px;
	max-width: 400px;
}

.groups-management__panel h3 {
	font-size: 14px;
	font-weight: 600;
	margin-bottom: 8px;
}

.groups-management__filter {
	width: 100%;
	margin-bottom: 8px;
}

.groups-management__list {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	height: 300px;
	overflow-y: auto;
}

.groups-management__item {
	display: flex;
	align-items: center;
	padding: 8px 12px;
	cursor: pointer;
	border-bottom: 1px solid var(--color-border-dark);
	gap: 8px;
}

.groups-management__item:last-child {
	border-bottom: none;
}

.groups-management__item:hover {
	background: var(--color-background-hover);
}

.groups-management__item--selected {
	background: var(--color-primary-element-light) !important;
}

.groups-management__item--deleted {
	opacity: 0.5;
	text-decoration: line-through;
}

.groups-management__drag-handle {
	cursor: grab;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.groups-management__name {
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.groups-management__add-icon {
	color: var(--color-text-maxcontrast);
}

.groups-management__remove {
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-error-text);
	padding: 2px 6px;
	font-variant-emoji: text;
}

.groups-management__empty {
	padding: 12px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.groups-management__user-info {
	margin-bottom: 24px;
}

.groups-management__user-info h3 {
	font-size: 14px;
	font-weight: 600;
	margin-bottom: 8px;
}

.groups-management__user-info p {
	margin-bottom: 4px;
}

.groups-management__actions {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-top: 12px;
}

.groups-management__warning {
	color: var(--color-error-text);
	font-style: italic;
	font-size: 13px;
}

.groups-management__btn-warning {
	color: var(--color-warning);
}
</style>
