<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * The change feed exposes every principal's collection URIs, so it is limited
 * to the identities that already see everything: the configured bot account
 * (the Connector's app password) and admins (for diagnostics via curl).
 */
class ChangeFeedGuard {

	public function __construct(
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private ChangeNotificationConfig $config,
	) {}

	public function isAllowed(): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		$bot = $this->config->botUser();
		if ($bot !== '' && $user->getUID() === $bot) {
			return true;
		}

		return $this->groupManager->isAdmin($user->getUID());
	}
}
