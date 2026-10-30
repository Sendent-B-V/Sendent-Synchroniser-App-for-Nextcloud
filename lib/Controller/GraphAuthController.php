<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Controller;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Db\AuthStatus;
use OCA\SendentSynchroniser\Db\AuthStatusMapper;
use OCA\SendentSynchroniser\Service\StartLinkBuilder;
use OCA\SendentSynchroniser\Service\StartLinkUnavailableException;
use OCA\SendentSynchroniser\Service\SyncUserService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Microsoft sign-in routes on the Nextcloud side: the start link, the
 * status callback from the sync service, and the logged-in user's own
 * status for the reconnect banner.
 */
class GraphAuthController extends Controller {

	public function __construct(
		string $AppName,
		IRequest $request,
		private StartLinkBuilder $startLinkBuilder,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private AuthStatusMapper $authStatusMapper,
		private IUserManager $userManager,
		private IAppConfig $appConfig,
		private SyncUserService $syncUserService,
	) {
		parent::__construct($AppName, $request);
	}

	/**
	 * Sign-in status callback. The sync service POSTs one JSON status per call
	 * with the service-account Basic credentials. Admin stays required (that
	 * is the authorisation), CSRF is off (no session, no token), exactly like
	 * UserController::invalidate. Parameters are nullable on purpose: the
	 * dispatcher fills them from the JSON body by name and a missing field
	 * must become our own 400, not a TypeError.
	 *
	 * Answers 200 with an empty body when stored or ignored as stale, 400 for
	 * a malformed body, 404 for an unknown user. The service treats 400 and
	 * 404 as permanent and retries everything else.
	 *
	 * @NoCSRFRequired
	 */
	public function status(
		?string $provider = null,
		?string $userId = null,
		?string $status = null,
		?string $reason = null,
		?string $changedAt = null,
	): Response {
		// Validate before touching anything: a bad body is a permanent 400 for
		// the service, so decide that first. One check per field, same shape
		// each time; reason is free text and is not checked.
		if ($provider === null || $provider === '') {
			return new JSONResponse(['message' => 'Missing provider'], Http::STATUS_BAD_REQUEST);
		}
		if ($userId === null || $userId === '') {
			return new JSONResponse(['message' => 'Missing userId'], Http::STATUS_BAD_REQUEST);
		}
		if ($status === null || $status === '') {
			return new JSONResponse(['message' => 'Missing status'], Http::STATUS_BAD_REQUEST);
		}
		if ($changedAt === null || $changedAt === '') {
			return new JSONResponse(['message' => 'Missing changedAt'], Http::STATUS_BAD_REQUEST);
		}

		if (!in_array($provider, Constants::AUTH_PROVIDERS, true)) {
			return new JSONResponse(['message' => 'Unknown provider'], Http::STATUS_BAD_REQUEST);
		}
		if (!in_array($status, Constants::AUTH_STATUSES, true)) {
			return new JSONResponse(['message' => 'Unknown status'], Http::STATUS_BAD_REQUEST);
		}
		if (preg_match(Constants::AUTH_CHANGED_AT_PATTERN, $changedAt) !== 1) {
			return new JSONResponse(['message' => 'Malformed changedAt'], Http::STATUS_BAD_REQUEST);
		}

		// 404 means the account, not consent: a retracted user keeps their
		// status so a later re-consent does not start from a stale copy.
		if (!$this->userManager->userExists($userId)) {
			return new JSONResponse(['message' => 'Unknown userId'], Http::STATUS_NOT_FOUND);
		}

		// The pattern check above guarantees this parses; UTC seconds so the
		// stale comparison below is on integers.
		$changedAtTimestamp = (new \DateTimeImmutable($changedAt, new \DateTimeZone('UTC')))->getTimestamp();

		// Retries arrive late and out of order: an older changedAt must not
		// overwrite a newer status. Equal means the same transition twice,
		// overwriting is harmless.
		$existingStatus = $this->authStatusMapper->findByUidAndProvider($userId, $provider);
		if ($existingStatus !== null && $existingStatus->getChangedAt() > $changedAtTimestamp) {
			return new Response(Http::STATUS_OK);
		}

		$row = $existingStatus ?? new AuthStatus();
		if ($existingStatus === null) {
			$row->setUid($userId);
			$row->setProvider($provider);
		}
		$row->setStatus($status);
		// Reason only travels with needsReconnect; any other status clears it
		$row->setReason($status === Constants::AUTH_STATUS_NEEDS_RECONNECT ? $reason : null);
		$row->setChangedAt($changedAtTimestamp);
		if ($existingStatus === null) {
			$this->authStatusMapper->insert($row);
		} else {
			$this->authStatusMapper->update($row);
		}

		// Under perUserStrict a broken sign-in means nothing syncs, so the user
		// goes back through consent; under fallback the service account keeps
		// syncing them and their consent stays valid. After the stale check on
		// purpose: a late retry must not knock out a user who reconnected.
		$signInMode = Constants::normaliseGraphAuthMethod($this->appConfig->getAppValue(Constants::GRAPH_AUTH_METHOD_KEY, ''));
		if ($signInMode === Constants::GRAPH_AUTH_METHOD_PER_USER_STRICT && $status === Constants::AUTH_STATUS_NEEDS_RECONNECT) {
			$this->syncUserService->invalidateUser($userId);
		}

		return new Response(Http::STATUS_OK);
	}

	/**
	 * The logged-in user's own Graph status, for the settings page and the
	 * reconnect modal. Ordinary user, CSRF on, never callable by the service.
	 *
	 * @NoAdminRequired
	 */
	public function myStatus(): JSONResponse {
		// TODO (banner): 401 without a user; otherwise the AuthStatus row for
		// (uid, 'graph') or null, plus the normalised sign-in mode.
		return new JSONResponse(null, Http::STATUS_NOT_IMPLEMENTED);
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
