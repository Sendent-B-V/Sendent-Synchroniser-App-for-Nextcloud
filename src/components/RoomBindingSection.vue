<template>
	<fieldset class="rooms-binding">
		<legend class="rooms-binding__legend">
			{{ t('sendentsynchroniser', 'External sync binding') }}
		</legend>

		<!-- No binding -->
		<p v-if="!binding" class="rooms-binding__empty">
			{{ t('sendentsynchroniser', 'This room is not linked to an external service.') }}
			<span class="rooms-binding__hint">
				{{ t('sendentsynchroniser', 'Bindings are managed by the synchronisation service or by the occ CLI — they cannot be created or removed from this UI.') }}
			</span>
		</p>

		<!-- Bound: read-only display -->
		<dl v-else class="rooms-binding__props">
			<div class="rooms-binding__prop">
				<dt>{{ t('sendentsynchroniser', 'Kind') }}</dt>
				<dd>{{ binding.kind }}</dd>
			</div>
			<div class="rooms-binding__prop">
				<dt>{{ t('sendentsynchroniser', 'External identifier') }}</dt>
				<dd><code>{{ binding.externalId }}</code></dd>
			</div>
			<div class="rooms-binding__prop">
				<dt>{{ t('sendentsynchroniser', 'Status') }}</dt>
				<dd>
					<span class="rooms-binding__badge"
						:class="`rooms-binding__badge--${binding.state}`">
						{{ binding.state }}
					</span>
				</dd>
			</div>
			<div v-if="binding.lastSyncedAt" class="rooms-binding__prop">
				<dt>{{ t('sendentsynchroniser', 'Last synced') }}</dt>
				<dd>{{ formatDate(binding.lastSyncedAt) }}</dd>
			</div>
			<div v-if="binding.lastError" class="rooms-binding__prop">
				<dt>{{ t('sendentsynchroniser', 'Last error') }}</dt>
				<dd class="rooms-binding__error">{{ binding.lastError }}</dd>
			</div>
			<div class="rooms-binding__prop">
				<dt>{{ t('sendentsynchroniser', 'Events') }}</dt>
				<dd>
					{{ t('sendentsynchroniser', '{p} pushed', { p: String(binding.stats.eventsPushed) }) }}
					·
					{{ t('sendentsynchroniser', '{p} pulled', { p: String(binding.stats.eventsPulled) }) }}
				</dd>
			</div>
		</dl>

		<p v-if="!licensed && !binding" class="rooms-binding__hint rooms-binding__hint--license">
			{{ t('sendentsynchroniser', 'Note: bidirectional room sync is a Premium feature.') }}
		</p>
	</fieldset>
</template>

<script setup lang="ts">
import { translate as t } from '@nextcloud/l10n'
import type { RoomBindingDto } from '../services/roomsApi'

defineProps<{
	binding: RoomBindingDto | null
	licensed: boolean
}>()

function formatDate(iso: string): string {
	try {
		return new Date(iso).toLocaleString()
	} catch {
		return iso
	}
}
</script>

<style scoped>
.rooms-binding {
	display: block !important;
	border: 1px solid var(--color-border, #ccc);
	padding: 12px 16px;
	border-radius: 6px;
	margin: 0 !important;
}
.rooms-binding__legend {
	font-weight: 600;
	padding: 0 6px;
}
.rooms-binding__empty {
	color: var(--color-text-maxcontrast, #555);
	font-size: 13px;
	margin: 0;
	display: block;
}
.rooms-binding__hint {
	display: block;
	margin-top: 4px;
	font-size: 12px;
	color: var(--color-text-maxcontrast, #777);
}
.rooms-binding__hint--license { margin-top: 8px; }

.rooms-binding__props {
	display: grid !important;
	grid-template-columns: max-content 1fr;
	column-gap: 16px;
	row-gap: 6px;
	margin: 0;
	font-size: 13px;
}
.rooms-binding__prop {
	display: contents;
}
.rooms-binding__prop dt {
	color: var(--color-text-maxcontrast, #555);
	font-weight: 500;
}
.rooms-binding__prop dd {
	margin: 0;
	word-break: break-word;
}
.rooms-binding__error { color: #c62828; }

.rooms-binding__badge {
	display: inline-block;
	padding: 1px 8px;
	border-radius: 10px;
	font-size: 12px;
	background: var(--color-background-darker, #ddd);
	text-transform: capitalize;
}
.rooms-binding__badge--pending { background: #888; color: #fff; }
.rooms-binding__badge--syncing { background: #1976d2; color: #fff; }
.rooms-binding__badge--completed { background: #2e7d32; color: #fff; }
.rooms-binding__badge--failed { background: #c62828; color: #fff; }
.rooms-binding__badge--idle { background: #aaa; color: #fff; }
</style>
