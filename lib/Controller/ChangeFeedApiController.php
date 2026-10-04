<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Controller;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeFeedGuard;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

/**
 * The change feed: what the Connector reads whenever it checks for changes —
 * on every hint, on (re)connect, and on its poll timer when notify_push is
 * unavailable. Authenticated with the bot account's app password.
 */
class ChangeFeedApiController extends ApiController {

	public function __construct(
		string $appName,
		IRequest $request,
		private ChangeFeedGuard $guard,
		private ChangeLedgerService $ledger,
		private ChangeNotificationConfig $config,
		private CursorService $cursor,
		private NotifyPushAvailability $availability,
		private IConfig $serverConfig,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function config(): DataResponse {
		if (!$this->guard->isAllowed()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		return new DataResponse([
			'transport' => $this->availability->effectiveTransport(),
			'ws_url' => $this->availability->websocketUrl(),
			'message_name' => Constants::CN_MESSAGE_NAME,
			'poll_interval' => $this->config->pollInterval(),
			'reread_overlap' => Constants::CN_REREAD_OVERLAP,
			// Keys the Connector's stored cursor: a reinstalled Nextcloud restarts its sequence.
			'instance' => $this->serverConfig->getSystemValueString('instanceid'),
		]);
	}

	/**
	 * Single indexed range scan; the bounded table keeps this cheap at any user count.
	 *
	 * Sequence numbers are handed out before the rows carrying them commit, so
	 * this read can see row 11 while row 10 is still uncommitted. Two rules
	 * keep row 10 from ending up below the Connector's cursor:
	 *
	 *  1. Before reading, raise the fence (CursorService) to the counter's
	 *     current value F. A row numbered at or below F that commits after the
	 *     read sees the raised fence once its writer has committed, and its
	 *     writer re-stamps it above F (ChangeLedgerService::confirm()).
	 *  2. Never return a cursor above F. A row above F that was still
	 *     uncommitted is not re-stamped, so it must stay above the cursor;
	 *     rows above F that this read did see are simply read again next time.
	 *
	 * Without a distributed cache there is no fence; the Connector's overlap
	 * re-read is then the only protection.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function changes(int $since = 0, int $limit = Constants::CN_CHANGES_LIMIT_DEFAULT): DataResponse {
		if (!$this->guard->isAllowed()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$since = max(0, $since);
		$limit = max(1, min(Constants::CN_CHANGES_LIMIT_MAX, $limit));

		$fence = $this->cursor->current();
		$this->cursor->raiseFence($fence);

		// Fetch one row past the page to compute has_more without a COUNT.
		$rows = $this->ledger->rows($since, $limit + 1);
		$hasMore = count($rows) > $limit;
		if ($hasMore) {
			array_pop($rows);
		}

		$highest = $since;
		$refs = [];
		foreach ($rows as $row) {
			$highest = max($highest, (int)$row->getChangeSeq());
			// q lets the Connector skip a collection version it already handled
			// when its overlap re-reads the row.
			$refs[] = $row->toReference($since)->jsonSerialize() + ['q' => (int)$row->getChangeSeq()];
		}
		$cursor = max($since, min($highest, $fence));

		return new DataResponse([
			'v' => Constants::CN_FEED_VERSION,
			'instance' => $this->serverConfig->getSystemValueString('instanceid'),
			'cursor' => $cursor,
			'refs' => $refs,
			'has_more' => $hasMore,
		]);
	}
}
