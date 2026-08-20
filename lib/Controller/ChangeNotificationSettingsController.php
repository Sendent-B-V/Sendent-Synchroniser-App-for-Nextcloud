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

	/** The settings page's "Flush now" button; also useful while debugging. */
	public function flushNow(): DataResponse {
		$signal = $this->publisher->flush();

		return new DataResponse([
			'flushed' => $signal !== null,
			'cursor' => $signal['cursor'] ?? null,
			'refs' => is_array($signal['refs'] ?? null) ? count($signal['refs']) : 0,
			'truncated' => $signal['truncated'] ?? false,
		]);
	}
}
