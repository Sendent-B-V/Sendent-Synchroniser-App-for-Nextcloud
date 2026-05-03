import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (path: string): string => generateUrl(`/apps/sendentsynchroniser/api/1.0${path}`)

export interface RoomBindingDto {
	kind: string
	externalId: string
	state: 'pending' | 'syncing' | 'completed' | 'failed' | 'idle'
	linkVersion: number
	lastSyncedAt: string | null
	lastError: string | null
	initialSyncRequested: boolean
	stats: { eventsPushed: number; eventsPulled: number }
}

export interface RoomDto {
	id: string
	name: string
	email: string | null
	capacity: number | null
	roomNumber: string | null
	floor: string | null
	address: string | null
	roomType: string
	description: string | null
	facilities?: string[]
	groupId: string | null
	active: boolean
	binding?: RoomBindingDto | null
	backingPrincipalUri: string
	backingCalendarUri: string
	createdAt: string
	updatedAt: string
}

export interface RoomGroupDto {
	id: string
	name: string
	description: string | null
}

export interface RoomPermissionDto {
	id: number
	roomId: string | null
	groupId: string | null
	role: 'viewer' | 'booker' | 'manager'
	principalType: 'user' | 'group'
	principalId: string
}

export interface BookingDto {
	uri: string
	calendardata: string
}

// ---------- Pagination ----------

export interface PagedResponse<T> {
	items: T[]
	page: number
	perPage: number
	total: number
}

export interface ListParams {
	page?: number
	perPage?: number
	q?: string
}

// ---------- Rooms ----------

export const listRooms = (params: ListParams = {}) =>
	axios.get<PagedResponse<RoomDto>>(url('/rooms'), { params })
export const getRoom = (id: string) => axios.get<RoomDto>(url(`/rooms/${id}`))
export const createRoom = (data: Partial<RoomDto>) => axios.post<RoomDto>(url('/rooms'), data)
export const updateRoom = (id: string, data: Partial<RoomDto>) => axios.patch<RoomDto>(url(`/rooms/${id}`), data)
export const deleteRoom = (id: string) => axios.delete(url(`/rooms/${id}`))

// ---------- Bindings ----------

export const putBinding = (id: string, kind: string, externalId: string, config: Record<string, unknown> = {}) =>
	axios.put<RoomBindingDto>(url(`/rooms/${id}/binding`), { kind, externalId, config })
export const deleteBinding = (id: string) => axios.delete(url(`/rooms/${id}/binding`))
export const retryBinding = (id: string) => axios.post<RoomBindingDto>(url(`/rooms/${id}/binding/retry`))

// ---------- Room groups ----------

export const listGroups = (params: ListParams = {}) =>
	axios.get<PagedResponse<RoomGroupDto>>(url('/room-groups'), { params })
export const createGroup = (data: Partial<RoomGroupDto>) => axios.post<RoomGroupDto>(url('/room-groups'), data)
export const updateGroup = (id: string, data: Partial<RoomGroupDto>) => axios.patch<RoomGroupDto>(url(`/room-groups/${id}`), data)
export const deleteGroup = (id: string) => axios.delete(url(`/room-groups/${id}`))

// ---------- Permissions ----------

export const listPermissions = (roomId: string) =>
	axios.get<{ permissions: RoomPermissionDto[] }>(url(`/rooms/${roomId}/permissions`))
export const grantPermission = (
	roomId: string,
	role: RoomPermissionDto['role'],
	principalType: RoomPermissionDto['principalType'],
	principalId: string,
) =>
	axios.post<RoomPermissionDto>(url(`/rooms/${roomId}/permissions`), { role, principalType, principalId })
export const revokePermission = (roomId: string, permId: number) =>
	axios.delete(url(`/rooms/${roomId}/permissions/${permId}`))

// ---------- Bookings (admin) ----------

export const listBookings = (roomId: string, from: string, to: string) =>
	axios.get<{ events: BookingDto[] }>(url(`/rooms/${roomId}/bookings`), { params: { from, to } })
export const deleteBooking = (roomId: string, uid: string) =>
	axios.delete(url(`/rooms/${roomId}/bookings/${uid}`))
