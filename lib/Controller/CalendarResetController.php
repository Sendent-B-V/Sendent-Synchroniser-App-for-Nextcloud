<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Controller;

use OCA\SendentSynchroniser\Service\CalendarResetService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class CalendarResetController extends Controller {

	public function __construct(
		string $AppName,
		IRequest $request,
		private CalendarResetService $calendarResetService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct($AppName, $request);
	}

	private function uid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}

	/**
	 * 500 means the scan failed; the client must not read that as "no".
	 *
	 * @NoAdminRequired
	 */
	public function status(): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['applicable' => false], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$applicable = $this->calendarResetService->shouldOffer($uid);
		} catch (\Throwable $e) {
			$this->logger->error('Calendar reset status check failed for user "' . $uid . '"', ['exception' => $e]);
			return new JSONResponse(['applicable' => false], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse(['applicable' => $applicable]);
	}

	/**
	 * OK, Skipped (guard refused, nothing changed) or 500 Error (rolled back).
	 *
	 * @NoAdminRequired
	 */
	public function execute(): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['status' => 'Error'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$done = $this->calendarResetService->reset($uid);
		} catch (\Throwable $e) {
			$this->logger->error('Calendar reset endpoint failed for user "' . $uid . '"', ['exception' => $e]);
			return new JSONResponse(['status' => 'Error'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
		return new JSONResponse(['status' => $done ? 'OK' : 'Skipped']);
	}
}
