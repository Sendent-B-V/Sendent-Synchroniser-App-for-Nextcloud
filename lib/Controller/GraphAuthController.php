<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Controller;

use OCA\SendentSynchroniser\Service\StartLinkBuilder;
use OCA\SendentSynchroniser\Service\StartLinkUnavailableException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Microsoft sign-in routes on the Nextcloud side: the start link now, the
 * status callback from the sync service later.
 */
class GraphAuthController extends Controller {

	public function __construct(
		string $AppName,
		IRequest $request,
		private StartLinkBuilder $startLinkBuilder,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct($AppName, $request);
	}

	/**
	 * Sends the browser to the sync service's start endpoint with a signed
	 * link. This is a top-level navigation, not a fetch, so the caller must
	 * append `requesttoken=` as a query parameter: Nextcloud reads it from GET
	 * and the CSRF check stays on. No NoCSRFRequired here, on purpose.
	 *
	 * @NoAdminRequired
	 */
	public function start(): Response {
		$uid = $this->userSession->getUser()?->getUID();
		if ($uid === null) {
			return new JSONResponse(['error' => 'not logged in'], Http::STATUS_UNAUTHORIZED);
		}
		try {
			$link = $this->startLinkBuilder->build($uid);
			return new RedirectResponse($link);
		} catch (StartLinkUnavailableException $e) {
			// Never log the link or the secret; the reason is enough for an admin.
			$this->logger->info('Start link unavailable for user {uid}: {reason}', ['uid' => $uid, 'reason' => $e->getReason()]);
			return new JSONResponse(['reason' => $e->getReason()], Http::STATUS_CONFLICT);
		}
	}
}
