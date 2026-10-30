<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<AuthStatus>
 */
class AuthStatusMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'sndntsync_authstatus', AuthStatus::class);
	}

	/**
	 * The one row for this user and provider, or null when the service has
	 * never reported a status for them.
	 */
	public function findByUidAndProvider(string $uid, string $provider): ?AuthStatus {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('provider', $qb->createNamedParameter($provider)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}
}
