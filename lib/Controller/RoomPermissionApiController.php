<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Controller;

use OCA\SendentSynchroniser\Service\Room\PermissionService;
use OCA\SendentSynchroniser\Service\Room\RoomValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class RoomPermissionApiController extends Controller {
	public function __construct(string $appName, IRequest $request, private PermissionService $service) {
		parent::__construct($appName, $request);
	}

	public function indexForRoom(string $id): JSONResponse {
		$perms = array_map(static fn ($p) => $p->jsonSerialize(), $this->service->listForRoom($id));
		return new JSONResponse(['permissions' => $perms]);
	}

	public function grantOnRoom(string $id, string $role, string $principalType, string $principalId): JSONResponse {
		try {
			$p = $this->service->grantOnRoom($id, $role, $principalType, $principalId);
			return new JSONResponse($p->jsonSerialize(), Http::STATUS_CREATED);
		} catch (RoomValidationException $e) {
			return new JSONResponse(['error' => ['code' => 'validation', 'message' => $e->getMessage()]], Http::STATUS_BAD_REQUEST);
		}
	}

	public function revoke(string $id, int $permId): JSONResponse {
		$this->service->revoke($permId);
		return new JSONResponse(null, Http::STATUS_NO_CONTENT);
	}
}
