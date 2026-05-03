<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Controller;

use OCA\SendentSynchroniser\Service\Room\RoomService;
use OCA\SendentSynchroniser\Service\Room\RoomValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class RoomApiController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private RoomService $service,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @NoCSRFRequired
	 */
	public function index(int $page = 1, int $perPage = 30, ?string $q = null): JSONResponse {
		$result = $this->service->listPage($page, $perPage, $q);
		return new JSONResponse([
			'items'   => array_map(static fn ($r) => $r->jsonSerialize(), $result['items']),
			'page'    => $result['page'],
			'perPage' => $result['perPage'],
			'total'   => $result['total'],
		]);
	}

	public function create(
		string $id, string $name,
		?string $email = null, ?int $capacity = null,
		?string $roomNumber = null, ?string $floor = null,
		?string $address = null, ?string $roomType = null,
		?string $description = null, ?string $groupId = null,
		array $facilities = [],
	): JSONResponse {
		try {
			$room = $this->service->create([
				'id' => $id, 'name' => $name, 'email' => $email,
				'capacity' => $capacity, 'roomNumber' => $roomNumber,
				'floor' => $floor, 'address' => $address,
				'roomType' => $roomType, 'description' => $description,
				'groupId' => $groupId, 'facilities' => $facilities,
			]);
			return new JSONResponse($room->jsonSerialize(), Http::STATUS_CREATED);
		} catch (RoomValidationException $e) {
			return $this->error('validation', $e->getMessage(), Http::STATUS_BAD_REQUEST);
		}
	}

	public function show(string $id): JSONResponse {
		try {
			return new JSONResponse($this->service->get($id)->jsonSerialize());
		} catch (DoesNotExistException) {
			return $this->error('not_found', 'Room not found', Http::STATUS_NOT_FOUND);
		}
	}

	public function update(
		string $id, ?string $name = null, ?string $email = null,
		?int $capacity = null, ?string $roomNumber = null,
		?string $floor = null, ?string $address = null,
		?string $roomType = null, ?string $description = null,
		?string $groupId = null, ?bool $active = null,
		?array $facilities = null,
	): JSONResponse {
		try {
			$patch = array_filter([
				'name' => $name, 'email' => $email, 'capacity' => $capacity,
				'roomNumber' => $roomNumber, 'floor' => $floor,
				'address' => $address, 'roomType' => $roomType,
				'description' => $description, 'groupId' => $groupId,
				'active' => $active, 'facilities' => $facilities,
			], static fn ($v) => $v !== null);
			return new JSONResponse($this->service->update($id, $patch)->jsonSerialize());
		} catch (DoesNotExistException) {
			return $this->error('not_found', 'Room not found', Http::STATUS_NOT_FOUND);
		} catch (RoomValidationException $e) {
			return $this->error('validation', $e->getMessage(), Http::STATUS_BAD_REQUEST);
		}
	}

	public function destroy(string $id): JSONResponse {
		try {
			$this->service->delete($id);
			return new JSONResponse(null, Http::STATUS_NO_CONTENT);
		} catch (DoesNotExistException) {
			return $this->error('not_found', 'Room not found', Http::STATUS_NOT_FOUND);
		}
	}

	private function error(string $code, string $message, int $status): JSONResponse {
		return new JSONResponse(['error' => ['code' => $code, 'message' => $message]], $status);
	}
}
