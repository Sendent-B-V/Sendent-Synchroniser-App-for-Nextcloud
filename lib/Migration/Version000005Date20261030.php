<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `sndntsync_authstatus`: the per-user, per-provider copy of the sign-in
 * status the sync service pushes through the status callback. One row per uid and
 * provider; no foreign key to sndntsync_users, consent and sign-in status have
 * separate lifetimes.
 */
class Version000005Date20261030 extends SimpleMigrationStep {

	public const TABLE = 'sndntsync_authstatus';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(self::TABLE)) {
			$table = $schema->createTable(self::TABLE);
			$table->addColumn('id', 'bigint', [
				'autoincrement' => true,
				'notnull' => true,
			]);
			// Same length as sndntsync_users.uid.
			$table->addColumn('uid', 'string', [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('provider', 'string', [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('status', 'string', [
				'notnull' => true,
				'length' => 32,
			]);
			// Only with needsReconnect; cleared on any other status.
			$table->addColumn('reason', 'string', [
				'notnull' => false,
				'default' => null,
				'length' => 128,
			]);
			// UTC seconds; the stale check compares these as integers.
			$table->addColumn('changed_at', 'bigint', [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id']);
			// Name kept under 27 characters: Nextcloud 28 to 33 still apply the
			// Oracle identifier limit to index names.
			$table->addUniqueIndex(['uid', 'provider'], 'sndntsync_authstatus_uidprov');
		}

		return $schema;
	}
}
