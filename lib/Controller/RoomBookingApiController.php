<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Controller;

use DateTimeImmutable;
use OCA\SendentSynchroniser\Service\Room\BookingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class RoomBookingApiController extends Controller {
	public function __construct(string $appName, IRequest $request, private BookingService $service) {
		parent::__construct($appName, $request);
	}

	public function index(string $id, string $from, string $to): JSONResponse {
		try {
			$events = $this->service->listInRange(
				$id,
				new DateTimeImmutable($from),
				new DateTimeImmutable($to),
			);
			return new JSONResponse(['events' => $events]);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => ['code' => 'not_found', 'message' => 'Room not found']], Http::STATUS_NOT_FOUND);
		} catch (\Exception $e) {
			return new JSONResponse(['error' => ['code' => 'bad_date', 'message' => $e->getMessage()]], Http::STATUS_BAD_REQUEST);
		}
	}

	public function destroy(string $id, string $uid): JSONResponse {
		try {
			$deleted = $this->service->deleteByUid($id, $uid);
			return $deleted
				? new JSONResponse(null, Http::STATUS_NO_CONTENT)
				: new JSONResponse(['error' => ['code' => 'not_found', 'message' => 'Booking not found']], Http::STATUS_NOT_FOUND);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => ['code' => 'not_found', 'message' => 'Room not found']], Http::STATUS_NOT_FOUND);
		}
	}
}
