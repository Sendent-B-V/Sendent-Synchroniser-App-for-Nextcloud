<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;

/**
 * Which collections the ledger records: only those owned by users the
 * Connector syncs — the same rule as SyncUserService::getValidUsers(), an
 * activated SyncUser in one of the active groups. Changes of anyone else
 * never reach the bot account.
 *
 * A calendar shared by a non-syncing owner is therefore not signalled, even
 * when a syncing user edits it; the Connector's periodic reconcile covers it.
 */
class SyncScope {

	private const USER_PRINCIPAL_PREFIX = 'principals/users/';

	/**
	 * Per process: one DAV request usually fires many events for one owner.
	 * @var array<string, bool>
	 */
	private array $synced = [];

	public function __construct(
		private SyncUserMapper $syncUserMapper,
		private IGroupManager $groupManager,
		private IAppConfig $appConfig,
	) {}

	public function includes(CollectionReference $ref): bool {
		$uid = substr($ref->principalUri, strlen(self::USER_PRINCIPAL_PREFIX));

		return $this->synced[$uid] ??= $this->isSyncedUser($uid);
	}

	private function isSyncedUser(string $uid): bool {
		$syncUsers = $this->syncUserMapper->findByUid($uid);
		if ($syncUsers === [] || (int)$syncUsers[0]->getActive() !== Constants::USER_STATUS_ACTIVE) {
			return false;
		}

		foreach ($this->activeGroups() as $gid) {
			if ($this->groupManager->isInGroup($uid, $gid)) {
				return true;
			}
		}

		return false;
	}

	/** @return string[] */
	private function activeGroups(): array {
		$decoded = json_decode($this->appConfig->getAppValue('activeGroups', ''), true);

		return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
	}
}
