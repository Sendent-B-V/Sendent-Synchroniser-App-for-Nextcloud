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
		if ($url !== '' && !str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
			return new DataResponse(['message' => 'Connector address must be an http(s) URL'], Http::STATUS_BAD_REQUEST);
		}

		$this->config->setConnectorUrl($url);

		return new DataResponse(['connectorUrl' => $url]);
	}

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

	/** The admin session can't see bot-addressed frames, so this confirms publishing, not delivery. */
	public function sendPing(): DataResponse {
		$nonce = bin2hex(random_bytes(8));
		$published = $this->transport->publishPing([
			'nonce' => $nonce,
			'sent_at' => $this->time->getTime(),
		]);

		return new DataResponse(['published' => $published, 'nonce' => $nonce]);
	}

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
			$this->config->setWebhookSecret($secret);
			// updateSensitive() redacts the secret from occ config:list/reports;
			// it exists since NC 29, so it stays unredacted on NC 28.
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
