<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Controller;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\SetupCheck;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserManager;

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
		private SetupCheck $setupCheck,
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

	/** Empty clears it: no account then receives hints or reads the feed, admins aside. */
	public function setBotUser(string $uid): DataResponse {
		if ($uid !== '' && !$this->userManager->userExists($uid)) {
			return new DataResponse(['message' => 'User does not exist'], Http::STATUS_BAD_REQUEST);
		}

		$this->config->setBotUser($uid);

		return new DataResponse(['botUser' => $uid]);
	}

	/** Clamped on write (and on read), so out-of-range input degrades to the nearest bound. */
	public function setPollInterval(int $pollInterval): DataResponse {
		$this->config->setPollInterval($pollInterval);

		return new DataResponse(['pollInterval' => $this->config->pollInterval()]);
	}

	/** The settings page's "Run test"; the same check as occ sendentsynchroniser:cn-check. */
	public function check(): DataResponse {
		return new DataResponse($this->setupCheck->run());
	}
}
