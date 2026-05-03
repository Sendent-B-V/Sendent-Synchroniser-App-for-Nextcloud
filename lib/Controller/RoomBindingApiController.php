<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Controller;

use DateTime;
use OCA\SendentSynchroniser\Db\RoomBinding;
use OCA\SendentSynchroniser\Service\Room\Binding\BindingValidationException;
use OCA\SendentSynchroniser\Service\Room\Binding\LicenseRequiredException;
use OCA\SendentSynchroniser\Service\Room\BindingService;
use OCA\SendentSynchroniser\Service\Room\RoomValidationException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class RoomBindingApiController extends Controller {

	private const VALID_STATES = [
		RoomBinding::STATE_PENDING,
		RoomBinding::STATE_SYNCING,
		RoomBinding::STATE_COMPLETED,
		RoomBinding::STATE_FAILED,
		RoomBinding::STATE_IDLE,
	];

	public function __construct(string $appName, IRequest $request, private BindingService $service) {
		parent::__construct($appName, $request);
	}

	public function put(string $id, string $kind, string $externalId, array $config = []): JSONResponse {
		try {
			$b = $this->service->set($id, $kind, $externalId, $config);
			return new JSONResponse($b->jsonSerialize());
		} catch (LicenseRequiredException $e) {
			return new JSONResponse(['error' => ['code' => 'license_required', 'message' => $e->getMessage()]], 402);
		} catch (BindingValidationException $e) {
			return new JSONResponse(['error' => ['code' => 'validation', 'message' => $e->getMessage()]], Http::STATUS_BAD_REQUEST);
		} catch (RoomValidationException $e) {
			return new JSONResponse(['error' => ['code' => 'validation', 'message' => $e->getMessage()]], Http::STATUS_BAD_REQUEST);
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => ['code' => 'not_found', 'message' => 'Room not found']], Http::STATUS_NOT_FOUND);
		}
	}

	public function delete(string $id): JSONResponse {
		$this->service->clear($id);
		return new JSONResponse(null, Http::STATUS_NO_CONTENT);
	}

	public function retry(string $id): JSONResponse {
		try {
			return new JSONResponse($this->service->retry($id)->jsonSerialize());
		} catch (DoesNotExistException) {
			return new JSONResponse(['error' => ['code' => 'not_found', 'message' => 'No binding for this room']], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * Status report from the external sync service.
	 *
	 * Authenticated like any other admin endpoint — the sync service connects
	 * as a configured NC admin user (typically via app password). Stale-version
	 * reports (older `linkVersion` than what we currently store) are silently
	 * ignored: the controller returns 204 anyway so the caller doesn't retry.
	 */
	public function status(
		string $id, int $linkVersion, string $state,
		?string $lastSyncedAt = null, ?string $error = null,
		?bool $initialSyncRequested = null,
		?int $eventsPushed = null, ?int $eventsPulled = null,
	): JSONResponse {
		if (!in_array($state, self::VALID_STATES, true)) {
			return new JSONResponse(['error' => ['code' => 'bad_state', 'message' => 'Unknown state']], Http::STATUS_BAD_REQUEST);
		}
		if ($state === RoomBinding::STATE_FAILED && ($error === null || $error === '')) {
			return new JSONResponse(['error' => ['code' => 'missing_error', 'message' => 'error required for state=failed']], Http::STATUS_BAD_REQUEST);
		}
		if ($state === RoomBinding::STATE_COMPLETED && $lastSyncedAt === null) {
			return new JSONResponse(['error' => ['code' => 'missing_lastSyncedAt', 'message' => 'lastSyncedAt required for state=completed']], Http::STATUS_BAD_REQUEST);
		}

		$ts = null;
		if ($lastSyncedAt !== null) {
			try {
				$ts = new DateTime($lastSyncedAt);
			} catch (\Exception) {
				return new JSONResponse(['error' => ['code' => 'bad_timestamp', 'message' => 'Invalid lastSyncedAt']], Http::STATUS_BAD_REQUEST);
			}
		}

		$found = $this->service->applyStatus(
			$id, $linkVersion, $state, $ts, $error,
			$initialSyncRequested, $eventsPushed, $eventsPulled,
		);
		if (!$found) {
			return new JSONResponse(['error' => ['code' => 'not_found', 'message' => 'No binding for room']], Http::STATUS_NOT_FOUND);
		}
		return new JSONResponse(null, Http::STATUS_NO_CONTENT);
	}
}
