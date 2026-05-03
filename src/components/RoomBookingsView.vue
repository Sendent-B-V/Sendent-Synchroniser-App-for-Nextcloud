<template>
	<div class="rooms-bookings">
		<div class="rooms-bookings__toolbar">
			<select v-model="selectedRoomId">
				<option :value="null" disabled>{{ t('sendentsynchroniser', 'Select a room…') }}</option>
				<option v-for="r in rooms.rooms" :key="r.id" :value="r.id">{{ r.name }}</option>
			</select>
			<label>
				{{ t('sendentsynchroniser', 'From') }}
				<input v-model="fromDate" type="date">
			</label>
			<label>
				{{ t('sendentsynchroniser', 'To') }}
				<input v-model="toDate" type="date">
			</label>
			<button type="button"
				:disabled="!selectedRoomId || loading"
				@click="reload">
				{{ t('sendentsynchroniser', 'Load') }}
			</button>
		</div>

		<p v-if="loading" class="rooms-bookings__status">{{ t('sendentsynchroniser', 'Loading…') }}</p>
		<p v-if="error" class="rooms-bookings__error">{{ error }}</p>

		<table v-if="pagedEvents.length > 0" class="rooms-bookings__table">
			<thead>
				<tr>
					<th>{{ t('sendentsynchroniser', 'Summary') }}</th>
					<th>{{ t('sendentsynchroniser', 'Start') }}</th>
					<th>{{ t('sendentsynchroniser', 'End') }}</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<!-- UID is the iCal identifier — kept on the row's title attr for tooltip
					 access but not surfaced as a column (admins rarely need it visible). -->
				<tr v-for="ev in pagedEvents" :key="ev.uid" :title="ev.uid">
					<td>{{ ev.summary }}</td>
					<td>{{ ev.dtstart }}</td>
					<td>{{ ev.dtend }}</td>
					<td>
						<button type="button"
							class="rooms-bookings__btn-danger"
							@click="onCancel(ev.uid)">
							{{ t('sendentsynchroniser', 'Cancel') }}
						</button>
					</td>
				</tr>
			</tbody>
		</table>
		<p v-else-if="!loading && selectedRoomId" class="rooms-bookings__empty">
			{{ t('sendentsynchroniser', 'No bookings in range.') }}
		</p>

		<Pagination
			:page="page"
			:per-page="perPage"
			:total="parsed.length"
			@update:page="(p) => page = p"
			@update:per-page="onPerPageChange" />
	</div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { useRoomsStore } from '../stores/rooms'
import * as api from '../services/roomsApi'
import type { BookingDto } from '../services/roomsApi'
import Pagination from './Pagination.vue'

const rooms = useRoomsStore()

const selectedRoomId = ref<string | null>(null)
const fromDate = ref<string>(new Date().toISOString().slice(0, 10))
const toDate = ref<string>(new Date(Date.now() + 30 * 24 * 3600_000).toISOString().slice(0, 10))
const loading = ref(false)
const error = ref<string | null>(null)
const events = ref<BookingDto[]>([])

const page = ref(1)
const perPage = ref(30)

interface ParsedEvent { uid: string; summary: string; dtstart: string; dtend: string }

const parsed = computed<ParsedEvent[]>(() => events.value.map(parseICal))
const pagedEvents = computed<ParsedEvent[]>(() => parsed.value.slice((page.value - 1) * perPage.value, page.value * perPage.value))

async function reload(): Promise<void> {
	if (!selectedRoomId.value) return
	loading.value = true
	error.value = null
	events.value = []
	try {
		const resp = await api.listBookings(
			selectedRoomId.value,
			new Date(fromDate.value).toISOString(),
			new Date(toDate.value).toISOString(),
		)
		events.value = resp.data.events
		page.value = 1
	} catch (e) {
		error.value = extractMessage(e)
	} finally {
		loading.value = false
	}
}

async function onCancel(uid: string): Promise<void> {
	if (!selectedRoomId.value) return
	if (!confirm(t('sendentsynchroniser', 'Cancel booking {uid}?', { uid }))) return
	try {
		await api.deleteBooking(selectedRoomId.value, uid)
		events.value = events.value.filter(ev => parseICal(ev).uid !== uid)
		const lastPage = Math.max(1, Math.ceil(parsed.value.length / perPage.value))
		if (page.value > lastPage) {
			page.value = lastPage
		}
	} catch (e) {
		error.value = extractMessage(e)
	}
}

function onPerPageChange(n: number): void {
	perPage.value = n
	page.value = 1
}

function parseICal(ev: BookingDto): ParsedEvent {
	const data = ev.calendardata
	return {
		uid: extract(data, 'UID') ?? '',
		summary: extract(data, 'SUMMARY') ?? '(no summary)',
		dtstart: extract(data, 'DTSTART') ?? '',
		dtend: extract(data, 'DTEND') ?? '',
	}
}

function extract(ical: string, field: string): string | null {
	const re = new RegExp(`(?:^|\\r?\\n)${field}(?:;[^:]*)?:([^\\r\\n]*)`)
	const m = ical.match(re)
	return m ? m[1] : null
}

function extractMessage(e: unknown): string {
	if (typeof e === 'object' && e !== null && 'response' in e) {
		const resp = (e as { response?: { data?: { error?: { message?: string } } } }).response
		return resp?.data?.error?.message ?? 'Request failed'
	}
	return e instanceof Error ? e.message : 'Request failed'
}
</script>

<style scoped>
.rooms-bookings__toolbar { display: flex; gap: 12px; align-items: end; margin-bottom: 12px; }
.rooms-bookings__toolbar label { display: flex; flex-direction: column; font-size: 13px; gap: 4px; }
.rooms-bookings__table { width: 100%; border-collapse: collapse; }
.rooms-bookings__table th, .rooms-bookings__table td { padding: 6px 10px; text-align: left; border-bottom: 1px solid var(--color-border, #eee); }
.rooms-bookings__status, .rooms-bookings__empty { color: var(--color-text-maxcontrast, #777); }
.rooms-bookings__error { color: #c62828; }
.rooms-bookings__btn-danger { background: #c62828; color: #fff; border: none; padding: 2px 8px; cursor: pointer; }
</style>
