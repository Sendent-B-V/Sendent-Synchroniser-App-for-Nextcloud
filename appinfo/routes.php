<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Create your routes in here. The name is the lowercase name of the controller
 * without the controller part, the stuff after the hash is the method.
 * e.g. page#index -> OCA\SendentSynchroniser\Controller\PageController->index()
 *
 * The controller class has to be registered in the application.php file since
 * it's instantiated in there
 */
return [
	'resources' => [
	],
	'routes' => [
		['name' => 'page#permit', 'url' => '/permit', 'verb' => 'GET'],
		['name' => 'page#health', 'url' => '/api/1.0/health', 'verb' => 'GET'],

		['name' => 'group#getSyncGroups', 'url' => '/api/1.0/groups/sync', 'verb' => 'GET'],
		['name' => 'group#generateAppPasswordsForUser', 'url' => '/api/1.0/groups/appPassword/{username}', 'verb' => 'GET'],
		['name' => 'group#getExternalGroupUsers', 'url' => '/api/1.0/groups/{groupid}/users', 'verb' => 'GET'],
		['name' => 'group#getExternalGroups', 'url' => '/api/1.0/groups/external', 'verb' => 'GET'],
		['name' => 'group#generateAppPasswordsForGroup', 'url' => '/api/1.0/groups/appPasswords/{groupid}', 'verb' => 'GET'],
		['name' => 'group#updateFromNewList', 'url' => '/api/1.0/groups/updateFromNewList', 'verb' => 'POST'],

			['name' => 'syncgroup#preflighted_cors', 'url' => '/api/1.0/{path}',
			'verb' => 'OPTIONS', 'requirements' => ['path' => '.+']]
	]
];
