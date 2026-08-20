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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IRequest;

/**
 * Transport B, and every transport's catch-up path: the Connector reads the
 * ledger through these endpoints with the bot account's app password (Basic
 * auth). Same payload schema as the websocket signal, so one Connector code
 * path consumes both.
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
		private \OCA\SendentSynchroniser\Service\ChangeNotification\SignalMetrics $metrics,
		private IConfig $serverConfig,
		private ITimeFactory $time,
		private \OCP\App\IAppManager $appManager,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Transport negotiation. The Connector calls this at startup and never
	 * has to guess which transport the instance supports.
	 *
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
			'batch_window' => $this->config->batchWindow(),
			'reread_overlap' => $this->config->rereadOverlap(),
			'cursor' => $this->cursor->current(),
			'bot_user' => $this->config->botUser(),
			'instance' => $this->serverConfig->getSystemValueString('instanceid'),
			'app_version' => $this->appManager->getAppVersion('sendentsynchroniser'),
		]);
	}

	/**
	 * One page of the ledger above `since`. A single indexed range scan; the
	 * bounded table keeps this cheap at any user count.
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

		// Fetch one row past the page to compute has_more without a COUNT.
		$rows = $this->ledger->rows($since, $limit + 1);
		$hasMore = count($rows) > $limit;
		if ($hasMore) {
			array_pop($rows);
		}

		$cursor = $since;
		$refs = [];
		foreach ($rows as $row) {
			$refs[] = $row->toReference($since)->jsonSerialize();
			$cursor = max($cursor, (int)$row->getChangeSeq());
		}

		return new DataResponse([
			'v' => Constants::CN_SIGNAL_VERSION,
			'instance' => $this->serverConfig->getSystemValueString('instanceid'),
			'cursor' => $cursor,
			'refs' => $refs,
			'has_more' => $hasMore,
		]);
	}

	/**
	 * Optional: the Connector reports how far it has read, so the admin
	 * settings can show lag. Ignoring this endpoint costs nothing.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function ack(int $cursor = 0): DataResponse {
		if (!$this->guard->isAllowed()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$this->config->setAck(max(0, $cursor), $this->time->getTime());

		return new DataResponse([], Http::STATUS_NO_CONTENT);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function health(): DataResponse {
		if (!$this->guard->isAllowed()) {
			return new DataResponse(['message' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		$current = $this->cursor->current();

		return new DataResponse([
			'transport' => $this->availability->effectiveTransport(),
			'notify_push_ok' => $this->availability->cachedDaemonCheck()['ok'],
			'last_signal_at' => $this->config->lastSignalAt(),
			'ledger_rows' => $this->ledger->countCollections(),
			'cursor' => $current,
			'ack_cursor' => $this->config->ackCursor(),
			'ack_at' => $this->config->ackAt(),
			'connector_lag' => max(0, $current - $this->config->ackCursor()),
			'signals_last_hour' => $this->metrics->lastHour(),
		]);
	}
}
