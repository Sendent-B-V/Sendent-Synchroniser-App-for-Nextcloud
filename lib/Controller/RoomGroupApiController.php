<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Controller;

use OCA\SendentSynchroniser\Service\Room\RoomGroupService;
use OCA\SendentSynchroniser\Service\Room\RoomValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class RoomGroupApiController extends Controller {
	public function __construct(string $appName, IRequest $request, private RoomGroupService $service) {
		parent::__construct($appName, $request);
	}

	public function index(int $page = 1, int $perPage = 30, ?string $q = null): JSONResponse {
		$result = $this->service->listPage($page, $perPage, $q);
		return new JSONResponse([
			'items'   => array_map(static fn ($g) => $g->jsonSerialize(), $result['items']),
			'page'    => $result['page'],
			'perPage' => $result['perPage'],
			'total'   => $result['total'],
		]);
	}

	public function create(string $id, string $name, ?string $description = null): JSONResponse {
		try {
			$g = $this->service->create(['id' => $id, 'name' => $name, 'description' => $description]);
			return new JSONResponse($g->jsonSerialize(), Http::STATUS_CREATED);
		} catch (RoomValidationException $e) {
			return new JSONResponse(['error' => ['code' => 'validation', 'message' => $e->getMessage()]], Http::STATUS_BAD_REQUEST);
		}
	}

	public function show(string $id): JSONResponse {
		try {
			return new JSONResponse($this->service->get($id)->jsonSerialize());
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => ['code' => 'not_found', 'message' => 'Group not found']], Http::STATUS_NOT_FOUND);
		}
	}

	public function update(string $id, ?string $name = null, ?string $description = null): JSONResponse {
		try {
			$patch = array_filter(['name' => $name, 'description' => $description], static fn ($v) => $v !== null);
			return new JSONResponse($this->service->update($id, $patch)->jsonSerialize());
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => ['code' => 'not_found', 'message' => 'Group not found']], Http::STATUS_NOT_FOUND);
		} catch (RoomValidationException $e) {
			return new JSONResponse(['error' => ['code' => 'validation', 'message' => $e->getMessage()]], Http::STATUS_BAD_REQUEST);
		}
	}

	public function destroy(string $id): JSONResponse {
		$this->service->delete($id);
		return new JSONResponse(null, Http::STATUS_NO_CONTENT);
	}
}
