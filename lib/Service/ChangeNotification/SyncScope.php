<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Service\SyncUserService;

/**
 * Which collections the ledger records: only those owned by users the
 * Connector syncs (SyncUserService::isValidUser(), the rule behind the valid
 * users the app hands the Connector). Changes of anyone else never reach the
 * bot account.
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
		private SyncUserService $syncUsers,
	) {}

	public function includes(CollectionReference $ref): bool {
		$uid = substr($ref->principalUri, strlen(self::USER_PRINCIPAL_PREFIX));

		return $this->synced[$uid] ??= $this->syncUsers->isValidUser($uid);
	}
}
