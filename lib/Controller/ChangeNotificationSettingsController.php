<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Controller;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalPublisher;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Admin-only writes behind the "Change notifications" settings section.
 * No @NoAdminRequired anywhere in this class: the framework's default
 * admin requirement is the access control.
 */
class ChangeNotificationSettingsController extends ApiController {

	public function __construct(
		string $appName,
		IRequest $request,
		private ChangeNotificationConfig $config,
		private IUserManager $userManager,
		private NotifyPushAvailability $availability,
		private SignalPublisher $publisher,
		private \OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushTransport $transport,
		private \OCP\AppFramework\Utility\ITimeFactory $time,
		private \OCP\IAppConfig $globalAppConfig,
		private \OCP\BackgroundJob\IJobList $jobList,
		private \OCA\SendentSynchroniser\Service\ChangeNotification\ConnectorSetupCheck $setupCheck,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	public function setTransportMode(string $mode): DataResponse {
		try {
			$this->config->setTransportMode($mode);
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new DataResponse(['transportMode' => $mode]);
	}

	public function setBotUser(string $uid): DataResponse {
		if ($uid === '' || !$this->userManager->userExists($uid)) {
			return new DataResponse(['message' => 'User does not exist'], Http::STATUS_BAD_REQUEST);
		}

		$this->config->setBotUser($uid);

		return new DataResponse(['botUser' => $uid]);
	}

	public function setConnectorUrl(string $url): DataResponse {
		// Empty clears the value; otherwise require an absolute http(s) URL so
		// the diagnostics never show something unusable.
		if ($url !== '' && !str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
			return new DataResponse(['message' => 'Connector address must be an http(s) URL'], Http::STATUS_BAD_REQUEST);
		}

		$this->config->setConnectorUrl($url);

		return new DataResponse(['connectorUrl' => $url]);
	}

	/** Layered setup check: notify_push availability + connector TLS. */
	public function checkConnectorSetup(): DataResponse {
		return new DataResponse($this->setupCheck->run());
	}

	public function setBatching(int $batchWindow, int $maxRefsPerSignal, int $pollInterval): DataResponse {
		// Setters clamp on write (and getters on read), so out-of-range input
		// degrades to the nearest bound instead of erroring.
		$this->config->setBatchWindow($batchWindow);
		$this->config->setMaxRefsPerSignal($maxRefsPerSignal);
		$this->config->setPollInterval($pollInterval);

		return new DataResponse([
			'batchWindow' => $this->config->batchWindow(),
			'maxRefsPerSignal' => $this->config->maxRefsPerSignal(),
			'pollInterval' => $this->config->pollInterval(),
		]);
	}

	/** The settings page's "Run test" button: all availability checks, live. */
	public function runTest(): DataResponse {
		$appEnabled = $this->availability->isAppEnabled();
		$queueAvailable = $this->availability->queue() !== null;
		$daemon = $appEnabled ? $this->availability->refreshDaemonCheck()
			: ['ok' => false, 'at' => 0, 'message' => 'notify_push is not installed'];

		return new DataResponse([
			'app_enabled' => $appEnabled,
			'queue_available' => $queueAvailable,
			'daemon' => $daemon,
			'effective_transport' => $this->availability->effectiveTransport(),
		]);
	}

	public function flushNow(): DataResponse {
		$signal = $this->publisher->flush();

		return new DataResponse([
			'flushed' => $signal !== null,
			'cursor' => $signal['cursor'] ?? null,
			'refs' => is_array($signal['refs'] ?? null) ? count($signal['refs']) : 0,
			'truncated' => $signal['truncated'] ?? false,
		]);
	}

	/**
	 * Publishes a ping frame addressed to the bot. The admin session cannot
	 * see bot-addressed frames, so this confirms only that publishing
	 * succeeded — not delivery.
	 */
	public function sendPing(): DataResponse {
		$nonce = bin2hex(random_bytes(8));
		$published = $this->transport->publishPing([
			'nonce' => $nonce,
			'sent_at' => $this->time->getTime(),
		]);

		return new DataResponse(['published' => $published, 'nonce' => $nonce]);
	}

	/** The page reports whether (and how fast) the publish round-tripped. */
	public function reportPing(bool $ok, int $ms): DataResponse {
		$this->config->setRoundTrip($ok, $this->time->getTime(), max(0, $ms));

		return new DataResponse(['stored' => true]);
	}

	public function setWebhook(string $url, string $secret, bool $enabled): DataResponse {
		if ($enabled && !str_starts_with($url, 'https://')) {
			return new DataResponse(['message' => 'Webhook URL must be https'], Http::STATUS_BAD_REQUEST);
		}

		$this->config->setWebhookUrl($url);
		if ($secret !== '') {
			// Empty secret in the payload means "keep the stored one".
			$this->config->setWebhookSecret($secret);
			// Secrets are sensitive IAppConfig values (redacted from
			// occ config:list and reports). updateSensitive() exists since
			// NC 29; on NC 28 this call is skipped, so the secret stays
			// unredacted there.
			if (method_exists($this->globalAppConfig, 'updateSensitive')) {
				$this->globalAppConfig->updateSensitive(
					'sendentsynchroniser',
					\OCA\SendentSynchroniser\Constants::CN_WEBHOOK_SECRET_KEY,
					true
				);
			}
		}
		$this->config->setWebhookEnabled($enabled);

		return new DataResponse(['enabled' => $this->config->webhookEnabled()]);
	}

	/** "Send test" button: queues one synthetic signal through the webhook path. */
	public function sendTestWebhook(): DataResponse {
		if (!$this->config->webhookEnabled()) {
			return new DataResponse(['message' => 'Webhook is not enabled'], Http::STATUS_BAD_REQUEST);
		}

		$this->jobList->add(
			\OCA\SendentSynchroniser\BackgroundJob\SendWebhookSignal::class,
			['signal' => ['v' => 1, 'test' => true, 'prev' => 0, 'cursor' => 0, 'truncated' => false, 'refs' => []], 'attempt' => 1]
		);

		return new DataResponse(['queued' => true]);
	}
}
