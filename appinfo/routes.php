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
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'caldav#getGroupMemberSet', 'url' => '/api/1.0/caldav/groupmembership/{username}', 'verb' => 'GET'],
		['name' => 'group#getGroups', 'url' => '/api/1.0/groups/', 'verb' => 'GET'],
		['name' => 'group#generateAppPasswordsForGroup', 'url' => '/api/1.0/groups/generateAppPasswordsForGroup/{groupid}', 'verb' => 'GET'],
		['name' => 'group#getGroupUsers', 'url' => '/api/1.0/groups/{groupid}/users', 'verb' => 'GET'],
		['name' => 'caldav#setGroupMemberSet', 'url' => '/api/1.0/caldav/groupmembership/{username}/{serviceaccount}', 'verb' => 'GET'],
		['name' => 'caldav#preflighted_cors', 'url' => '/api/0.1/{path}',
			'verb' => 'OPTIONS', 'requirements' => ['path' => '.+']]
	]
];
