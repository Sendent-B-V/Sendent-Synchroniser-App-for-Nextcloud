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
	 * Decide whether to suppress Nextcloud's iTip + iMIP processing for the
	 * given CalDAV scheduling request.
	 *
	 * Returns true ONLY when:
	 *   - Graph API mode is enabled in admin settings, AND
	 *   - $uid is a member of at least one group listed in `activeGroups`.
	 *
	 * No SyncUser, consent, or calendar-URI check: the rule is "if the user is
	 * in the Sendent active group while Graph API mode is on, NC scheduling is
	 * out of the way." Admin owns `activeGroups` scoping.
	 *
	 * @param string|null $uid          the authenticated NC user, or null
	 * @param string      $requestPath  retained on the signature for source
	 *                                  compatibility with the plugin call
	 *                                  site; not consulted.
	 */
	public function shouldSuppress(?string $uid, string $requestPath): bool {
		if ($this->appConfig->getAppValue(
				Constants::GRAPH_API_MODE_KEY,
				Constants::GRAPH_API_MODE_DEFAULT
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
