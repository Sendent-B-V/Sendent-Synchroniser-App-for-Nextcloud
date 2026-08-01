<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service;

use OCA\SendentSynchroniser\Constants;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;

class SchedulingSuppressionService {

	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
	) {}

	/**
	 * Group membership is the sole user gate — SyncUser status is NOT
	 * consulted. Users in an active group always get suppression while
	 * the toggle is on, regardless of whether they've enrolled, retracted
	 * consent, or been invalidated.
	 *
	 * @param string $requestPath  not consulted (kept for call-site signature)
	 */
	public function shouldSuppress(?string $uid, string $requestPath): bool {
		if ($this->appConfig->getAppValue(
				Constants::DISABLE_ITIP_IMIP_KEY,
				Constants::DISABLE_ITIP_IMIP_DEFAULT
			) !== 'true') {
			return false;
		}

		if ($uid === null || $uid === '') {
			return false;
		}

		foreach ($this->getActiveGroups() as $gid) {
			if ($this->groupManager->isInGroup($uid, $gid)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Defensive parse of `activeGroups` app-config: handles missing, '',
	 * 'null', and malformed JSON by returning an empty list.
	 *
	 * @return string[]
	 */
	private function getActiveGroups(): array {
		$raw = $this->appConfig->getAppValue('activeGroups', '');
		if ($raw === '' || $raw === 'null') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}

		return array_values(array_filter($decoded, 'is_string'));
	}
}
