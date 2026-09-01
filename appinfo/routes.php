<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
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
		['name' => 'user#invalidateAll', 'url' => '/api/1.0/user/invalidateAll', 'verb' => 'POST'],

		['name' => 'settings#setActiveGroups', 'url' => '/api/1.0/settings/activeGroups', 'verb' => 'POST'],
		['name' => 'settings#setNotificationInterval', 'url' => '/api/1.0/settings/notificationInterval', 'verb' => 'POST'],
		['name' => 'settings#getNotificationMethod', 'url' => '/api/1.0/settings/notificationMethod', 'verb' => 'GET'],
		['name' => 'settings#setNotificationMethod', 'url' => '/api/1.0/settings/notificationMethod', 'verb' => 'POST'],
		['name' => 'settings#setReminderType', 'url' => '/api/1.0/settings/reminderType', 'verb' => 'POST'],
		['name' => 'settings#setSharedSecret', 'url' => '/api/1.0/settings/sharedSecret', 'verb' => 'POST'],
		['name' => 'settings#setIMAPSync', 'url' => '/api/1.0/settings/imapsync', 'verb' => 'POST'],
		['name' => 'settings#setGraphApiMode', 'url' => '/api/1.0/settings/graphApiMode', 'verb' => 'POST'],
		['name' => 'settings#setTrashbinScrub', 'url' => '/api/1.0/settings/trashbinScrub', 'verb' => 'POST'],
		['name' => 'settings#setEmailDomain', 'url' => '/api/1.0/settings/emailDomain', 'verb' => 'POST'],
		['name' => 'settings#setDefaultCalendar', 'url' => '/api/1.0/settings/defaultCalendar', 'verb' => 'POST'],
		['name' => 'settings#setDefaultAddressbook', 'url' => '/api/1.0/settings/defaultAddressbook', 'verb' => 'POST'],
		['name' => 'settings#shouldShowDialog', 'url' => '/api/1.0/settings/shouldShowDialog', 'verb' => 'GET'],
		['name' => 'settings#sendReminder', 'url' => '/api/1.0/settings/sendReminder', 'verb' => 'GET'],

		['name' => 'status_api#index', 'url' => '/api/1.0/status', 'verb' => 'GET'],

		['name' => 'change_feed_api#config', 'url' => '/api/1.0/notify/config', 'verb' => 'GET'],
		['name' => 'change_feed_api#changes', 'url' => '/api/1.0/notify/changes', 'verb' => 'GET'],
		['name' => 'change_feed_api#ack', 'url' => '/api/1.0/notify/ack', 'verb' => 'POST'],
		['name' => 'change_feed_api#health', 'url' => '/api/1.0/notify/health', 'verb' => 'GET'],

		['name' => 'change_notification_settings#setTransportMode', 'url' => '/api/1.0/settings/cnTransportMode', 'verb' => 'POST'],
		['name' => 'change_notification_settings#setBotUser', 'url' => '/api/1.0/settings/cnBotUser', 'verb' => 'POST'],
		['name' => 'change_notification_settings#setConnectorUrl', 'url' => '/api/1.0/settings/cnConnectorUrl', 'verb' => 'POST'],
		['name' => 'change_notification_settings#setBatching', 'url' => '/api/1.0/settings/cnBatching', 'verb' => 'POST'],
		['name' => 'change_notification_settings#runTest', 'url' => '/api/1.0/settings/cnRunTest', 'verb' => 'POST'],
		['name' => 'change_notification_settings#flushNow', 'url' => '/api/1.0/settings/cnFlushNow', 'verb' => 'POST'],
		['name' => 'change_notification_settings#sendPing', 'url' => '/api/1.0/settings/cnSendPing', 'verb' => 'POST'],
		['name' => 'change_notification_settings#reportPing', 'url' => '/api/1.0/settings/cnReportPing', 'verb' => 'POST'],
		['name' => 'change_notification_settings#setWebhook', 'url' => '/api/1.0/settings/cnWebhook', 'verb' => 'POST'],
		['name' => 'change_notification_settings#sendTestWebhook', 'url' => '/api/1.0/settings/cnWebhookTest', 'verb' => 'POST'],
		['name' => 'change_feed_api#setAllowList', 'url' => '/api/1.0/notify/allowlist', 'verb' => 'PUT'],

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
	],
];
