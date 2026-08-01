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

class CalendarResetController extends Controller {

	public function __construct(
		string $AppName,
		IRequest $request,
		private CalendarResetService $calendarResetService,
		private IUserSession $userSession,
	) {
		parent::__construct($AppName, $request);
	}

	private function uid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}

	/**
	 * Whether the one-time calendar reset should be offered to the current user.
	 *
	 * @NoAdminRequired
	 */
	public function status(): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['applicable' => false], Http::STATUS_UNAUTHORIZED);
		}
		return new JSONResponse(['applicable' => $this->calendarResetService->shouldOffer($uid)]);
	}

	/**
	 * Deletes and re-creates the current user's sync calendar. Refused once the
	 * user no longer holds a legacy-named token (i.e. after activation).
	 *
	 * @NoAdminRequired
	 */
	public function execute(): JSONResponse {
		$uid = $this->uid();
		if ($uid === null) {
			return new JSONResponse(['status' => 'Error'], Http::STATUS_UNAUTHORIZED);
		}
		$done = $this->calendarResetService->reset($uid);
		return new JSONResponse(['status' => $done ? 'OK' : 'Skipped']);
	}
}
