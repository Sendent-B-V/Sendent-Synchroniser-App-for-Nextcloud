<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

return [
	'routes' => [
		['name' => 'page#permit', 'url' => '/api/1.0/permit', 'verb' => 'GET'],
		['name' => 'page#health', 'url' => '/api/1.0/health', 'verb' => 'GET'],

		['name' => 'User#isValid', 'url' => '/api/1.0/user/isValid', 'verb' => 'GET'],
		['name' => 'User#invalidate', 'url' => '/api/1.0/user/invalidate', 'verb' => 'POST'],

		['name' => 'settings#setActiveGroups', 'url' => '/api/1.0/settings/activeGroups', 'verb' => 'POST'],
		['name' => 'settings#setNotificationMethod', 'url' => '/api/1.0/settings/notificationMethod', 'verb' => 'POST'],
		['name' => 'settings#setSharedSecret', 'url' => '/api/1.0/settings/sharedSecret', 'verb' => 'POST'],

	]
];
