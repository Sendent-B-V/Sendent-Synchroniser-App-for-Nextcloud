<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Migration;

use Closure;
use OCA\SendentSynchroniser\Constants;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * 2.1.0: adds `resetoffer`, flags every existing sync user for the one-time
 * calendar clean-up, deactivates ACTIVE users and revokes app tokens of both
 * names. Deploy the updated Exchange connector before this app version.
 */
class Version000004Date20260908 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('sndntsync_users')) {
			$table = $schema->getTable('sndntsync_users');
			if (!$table->hasColumn('resetoffer')) {
				$table->addColumn('resetoffer', 'smallint', [
					'notnull' => true,
					'default' => 0,
				]);
			}
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$stamped = $qb->update('sndntsync_users')
				->set('resetoffer', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
				->executeStatement();

			// NOCONSENT users stay as they are.
			$qb = $this->db->getQueryBuilder();
			$deactivated = $qb->update('sndntsync_users')
				->set('active', $qb->createNamedParameter(Constants::USER_STATUS_INACTIVE, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('active', $qb->createNamedParameter(Constants::USER_STATUS_ACTIVE, IQueryBuilder::PARAM_INT)))
				->executeStatement();

			// PARAM_STR: Oracle needs the CLOB cast on authtoken.name.
			$qb = $this->db->getQueryBuilder();
			$revoked = $qb->delete('authtoken')
				->where($qb->expr()->in('name', $qb->createNamedParameter(
					[Constants::TOKEN_NAME, Constants::TOKEN_NAME_LEGACY],
					IQueryBuilder::PARAM_STR_ARRAY
				), IQueryBuilder::PARAM_STR))
				->executeStatement();

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$output->info(sprintf(
			'Sendent Sync 2.1.0: flagged %d user(s) for the one-time calendar clean-up, deactivated %d, revoked %d app token(s). Users must re-consent.',
			$stamped, $deactivated, $revoked
		));
	}
}
