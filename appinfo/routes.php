<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
	'routes' => [
		['name' => 'page#health', 'url' => '/api/1.0/health', 'verb' => 'GET'],
		['name' => 'page#getConsentFlowPage', 'url' => '/api/1.0/getConsentFlowPage', 'verb' => 'GET'],

		['name' => 'user#activate', 'url' => '/api/1.0/user/activate', 'verb' => 'GET'],
		['name' => 'user#activateMail', 'url' => '/api/1.0/user/activateMail', 'verb' => 'GET'],
		['name' => 'user#getActiveUsers', 'url' => '/api/1.0/user/actives', 'verb' => 'GET'],
		['name' => 'user#invalidateSelf', 'url' => '/api/1.0/user/invalidate', 'verb' => 'GET'],
		['name' => 'user#invalidate', 'url' => '/api/1.0/user/invalidate', 'verb' => 'POST'],

		['name' => 'settings#setActiveGroups', 'url' => '/api/1.0/settings/activeGroups', 'verb' => 'POST'],
		['name' => 'settings#setNotificationInterval', 'url' => '/api/1.0/settings/notificationInterval', 'verb' => 'POST'],
		['name' => 'settings#getNotificationMethod', 'url' => '/api/1.0/settings/notificationMethod', 'verb' => 'GET'],
		['name' => 'settings#setNotificationMethod', 'url' => '/api/1.0/settings/notificationMethod', 'verb' => 'POST'],
		['name' => 'settings#setReminderType', 'url' => '/api/1.0/settings/reminderType', 'verb' => 'POST'],
		['name' => 'settings#setSharedSecret', 'url' => '/api/1.0/settings/sharedSecret', 'verb' => 'POST'],
		['name' => 'settings#setIMAPSync', 'url' => '/api/1.0/settings/imapsync', 'verb' => 'POST'],
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

	]
];
