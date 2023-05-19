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
		['name' => 'caldav#setGroupMemberSet', 'url' => '/api/1.0/caldav/groupmembership/{username}/{serviceaccount}', 'verb' => 'GET'],

		['name' => 'group#getExternalGroups', 'url' => '/api/1.0/groups/external', 'verb' => 'GET'],
		['name' => 'group#getSyncGroups', 'url' => '/api/1.0/groups/sync', 'verb' => 'GET'],
		['name' => 'group#generateAppPasswordsForGroup', 'url' => '/api/1.0/groups/appPasswords/{groupid}', 'verb' => 'GET'],
		['name' => 'group#generateAppPasswordsForUser', 'url' => '/api/1.0/groups/appPassword/{username}', 'verb' => 'GET'],
		['name' => 'group#getExternalGroupUsers', 'url' => '/api/1.0/groups/{groupid}/users', 'verb' => 'GET'],

		['name' => 'serviceaccount#index', 'url' => '/api/1.0/serviceaccounts', 'verb' => 'GET'],
		['name' => 'serviceaccount#show', 'url' => '/api/1.0/serviceaccounts/{id}', 'verb' => 'GET'],
		['name' => 'serviceaccount#showByUsername', 'url' => '/api/1.0/serviceaccounts/byUsername/{username}', 'verb' => 'GET'],
		['name' => 'serviceaccount#create', 'url' => '/api/1.0/serviceaccounts', 'verb' => 'POST'],
		['name' => 'serviceaccount#update', 'url' => '/api/1.0/serviceaccounts/{id}', 'verb' => 'PUT'],
		['name' => 'serviceaccount#destroy', 'url' => '/api/1.0/serviceaccounts/{id}', 'verb' => 'DELETE'],

		['name' => 'syncgroup#index', 'url' => '/api/1.0/syncgroups', 'verb' => 'GET'],
		['name' => 'syncgroup#show', 'url' => '/api/1.0/syncgroups/{id}', 'verb' => 'GET'],
		['name' => 'syncgroup#showByUsername', 'url' => '/api/1.0/syncgroups/byName/{name}', 'verb' => 'GET'],
		['name' => 'syncgroup#create', 'url' => '/api/1.0/syncgroups', 'verb' => 'POST'],
		['name' => 'syncgroup#update', 'url' => '/api/1.0/syncgroups/{id}', 'verb' => 'PUT'],
		['name' => 'syncgroup#destroy', 'url' => '/api/1.0/syncgroups/{id}', 'verb' => 'DELETE'],

		['name' => 'syncuser#index', 'url' => '/api/1.0/syncusers', 'verb' => 'GET'],
		['name' => 'syncuser#show', 'url' => '/api/1.0/syncusers/{id}', 'verb' => 'GET'],
		['name' => 'syncuser#showByUsername', 'url' => '/api/1.0/syncusers/byUsername/{username}', 'verb' => 'GET'],
		['name' => 'syncuser#showByGroupId', 'url' => '/api/1.0/syncusers/byGroup/{groupId}', 'verb' => 'GET'],
		['name' => 'syncuser#create', 'url' => '/api/1.0/syncusers', 'verb' => 'POST'],
		['name' => 'syncuser#update', 'url' => '/api/1.0/syncusers/{id}', 'verb' => 'PUT'],
		['name' => 'syncuser#destroy', 'url' => '/api/1.0/syncusers/{id}', 'verb' => 'DELETE'],

		['name' => 'caldav#preflighted_cors', 'url' => '/api/0.1/{path}',
			'verb' => 'OPTIONS', 'requirements' => ['path' => '.+']]
	]
];
