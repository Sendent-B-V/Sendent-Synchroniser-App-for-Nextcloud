<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;


/**
 * Per-event change rows for the change feed.
 *
 * sndntsync_dirty holds one row per calendar and is overwritten in place, so
 * it can only say "something in this calendar changed". This table says which
 * event changed. It is append-only: one row per changed event.
 *
 * What the table must guarantee:
 *
 * - A row is never updated after it is written, with one exception: its
 *   sequence number can be replaced by a higher one.
 * - A row carries the owner principal, the calendar, the event file name,
 *   the kind of change (created, updated, deleted), its own sequence number
 *   and the time it was written. It carries no event content.
 * - The feed can read one page of rows above a given sequence number, in
 *   sequence order, without a scan of the full table.
 * - The maintenance job can remove all rows older than a given time.
 * - All rows of one user can be removed.
 * - The same definition works on MySQL, MariaDB and PostgreSQL, on every
 *   Nextcloud version the app supports.
 *
 * The table grows with the number of event changes, not with the number of
 * calendars. Retention keeps it bounded.
 */
class Version000005Date20261120 extends SimpleMigrationStep {

	private const TABLE = 'sndntsync_evt_changes';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(self::TABLE)) {
			$table = $schema->createTable(self::TABLE);

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('principal_uri', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('calendar_uri', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('event_file_name', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('kind', Types::STRING, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('sequence_number', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('written_time', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
			]);

			$table->setPrimaryKey(['id']);

			// One index for the feed read, one for the cleanup.
			// No index on principal_uri: the delete for one user is rare.
			$table->addIndex(['sequence_number'], 'sndntsync_evt_changes_seq_ix');
			$table->addIndex(['written_time'], 'sndntsync_evt_changes_wtime_ix');
		}

		return $schema;
	}
}
