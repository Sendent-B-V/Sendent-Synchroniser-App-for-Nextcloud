<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

use OCA\SendentSynchroniser\Constants;

// Room subsystem is feature-flagged — see Constants::ROOMS_FEATURE_ENABLED.
// When the flag is off these routes are simply not registered; the controller
// classes and tests stay on disk untouched.
$roomRoutes = Constants::ROOMS_FEATURE_ENABLED ? [
	// Rooms — admin CRUD
	['name' => 'room_api#index',   'url' => '/api/1.0/rooms',         'verb' => 'GET'],
	['name' => 'room_api#create',  'url' => '/api/1.0/rooms',         'verb' => 'POST'],
	['name' => 'room_api#show',    'url' => '/api/1.0/rooms/{id}',    'verb' => 'GET'],
	['name' => 'room_api#update',  'url' => '/api/1.0/rooms/{id}',    'verb' => 'PATCH'],
	['name' => 'room_api#destroy', 'url' => '/api/1.0/rooms/{id}',    'verb' => 'DELETE'],

	// Room groups
	['name' => 'room_group_api#index',   'url' => '/api/1.0/room-groups',       'verb' => 'GET'],
	['name' => 'room_group_api#create',  'url' => '/api/1.0/room-groups',       'verb' => 'POST'],
	['name' => 'room_group_api#show',    'url' => '/api/1.0/room-groups/{id}',  'verb' => 'GET'],
	['name' => 'room_group_api#update',  'url' => '/api/1.0/room-groups/{id}',  'verb' => 'PATCH'],
	['name' => 'room_group_api#destroy', 'url' => '/api/1.0/room-groups/{id}',  'verb' => 'DELETE'],

	// Room permissions
	['name' => 'room_permission_api#indexForRoom', 'url' => '/api/1.0/rooms/{id}/permissions',           'verb' => 'GET'],
	['name' => 'room_permission_api#grantOnRoom',  'url' => '/api/1.0/rooms/{id}/permissions',           'verb' => 'POST'],
	['name' => 'room_permission_api#revoke',       'url' => '/api/1.0/rooms/{id}/permissions/{permId}',  'verb' => 'DELETE'],

	// Room bookings (admin)
	['name' => 'room_booking_api#index',   'url' => '/api/1.0/rooms/{id}/bookings',          'verb' => 'GET'],
	['name' => 'room_booking_api#destroy', 'url' => '/api/1.0/rooms/{id}/bookings/{uid}',    'verb' => 'DELETE'],

	// Room bindings — license-gated on PUT only.
	// `status` is consumed by the external sync service; it authenticates as
	// a NC admin user (app password) like any other admin caller.
	['name' => 'room_binding_api#put',    'url' => '/api/1.0/rooms/{id}/binding',         'verb' => 'PUT'],
	['name' => 'room_binding_api#delete', 'url' => '/api/1.0/rooms/{id}/binding',         'verb' => 'DELETE'],
	['name' => 'room_binding_api#retry',  'url' => '/api/1.0/rooms/{id}/binding/retry',   'verb' => 'POST'],
	['name' => 'room_binding_api#status', 'url' => '/api/1.0/rooms/{id}/binding/status',  'verb' => 'POST'],
] : [];

return [
	'routes' => array_merge([
		['name' => 'page#health', 'url' => '/api/1.0/health', 'verb' => 'GET'],
		['name' => 'page#getConsentFlowPage', 'url' => '/api/1.0/getConsentFlowPage', 'verb' => 'GET'],

		['name' => 'user#activate', 'url' => '/api/1.0/user/activate', 'verb' => 'GET'],
		['name' => 'user#activateMail', 'url' => '/api/1.0/user/activateMail', 'verb' => 'GET'],
		['name' => 'user#getActiveUsers', 'url' => '/api/1.0/user/actives', 'verb' => 'GET'],
		['name' => 'user#invalidateSelf', 'url' => '/api/1.0/user/invalidate', 'verb' => 'GET'],
		['name' => 'user#invalidate', 'url' => '/api/1.0/user/invalidate', 'verb' => 'POST'],
		['name' => 'user#invalidateAll', 'url' => '/api/1.0/user/invalidateAll', 'verb' => 'POST'],

		['name' => 'settings#setActiveGroups', 'url' => '/api/1.0/settings/activeGroups', 'verb' => 'POST'],
		['name' => 'settings#setNotificationInterval', 'url' => '/api/1.0/settings/notificationInterval', 'verb' => 'POST'],
		['name' => 'settings#getNotificationMethod', 'url' => '/api/1.0/settings/notificationMethod', 'verb' => 'GET'],
		['name' => 'settings#setNotificationMethod', 'url' => '/api/1.0/settings/notificationMethod', 'verb' => 'POST'],
		['name' => 'settings#setReminderType', 'url' => '/api/1.0/settings/reminderType', 'verb' => 'POST'],
		['name' => 'settings#setSharedSecret', 'url' => '/api/1.0/settings/sharedSecret', 'verb' => 'POST'],
		['name' => 'settings#setIMAPSync', 'url' => '/api/1.0/settings/imapsync', 'verb' => 'POST'],
		['name' => 'settings#setGraphApiMode', 'url' => '/api/1.0/settings/graphApiMode', 'verb' => 'POST'],
		['name' => 'settings#setEmailDomain', 'url' => '/api/1.0/settings/emailDomain', 'verb' => 'POST'],
		['name' => 'settings#setDefaultCalendar', 'url' => '/api/1.0/settings/defaultCalendar', 'verb' => 'POST'],
		['name' => 'settings#setDefaultAddressbook', 'url' => '/api/1.0/settings/defaultAddressbook', 'verb' => 'POST'],
		['name' => 'settings#shouldShowDialog', 'url' => '/api/1.0/settings/shouldShowDialog', 'verb' => 'GET'],
		['name' => 'settings#sendReminder', 'url' => '/api/1.0/settings/sendReminder', 'verb' => 'GET'],

		['name' => 'status_api#index', 'url' => '/api/1.0/status', 'verb' => 'GET'],

		[
			'name' => 'license_api#preflighted_cors',
			'url' => '/api/1.0/{path}',
			'verb' => 'OPTIONS',
			'requirements' => ['path' => '.+']
		],
		['name' => 'license_api#delete', 'url' => '/api/1.0/license', 'verb' => 'DELETE'],
		['name' => 'license_api#create', 'url' => '/api/1.0/license', 'verb' => 'POST'],
		['name' => 'license_api#show', 'url' => '/api/1.0/licensestatus', 'verb' => 'GET'],
		['name' => 'license_api#showInternal', 'url' => '/api/1.0/licensestatusinternal', 'verb' => 'GET'],
	], $roomRoutes),
];
