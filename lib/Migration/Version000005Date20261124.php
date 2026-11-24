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
 * Makes the sequence number of a per-event change row unique.
 *
 * The feed reads one page of rows above a number. If two rows had the same
 * number and a page ended between them, the second row was never sent. One
 * counter gives each row its own number, so this must not occur. The unique
 * index makes the database refuse it: a duplicate fails the insert and is
 * logged, it is not skipped in silence.
 *
 * The index covers this table only. A per-event row and a per-calendar row
 * are in two tables, and only the counter keeps their numbers apart.
 *
 * The unique index replaces the plain index on the same column. A second
 * run changes nothing.
 */
class Version000005Date20261124 extends SimpleMigrationStep {

	private const TABLE = 'sndntsync_evt_changes';
	private const COLUMN = 'sequence_number';
	private const UNIQUE_INDEX = 'sndntsync_evt_changes_seq_uq';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(self::TABLE)) {
			return null;
		}

		$table = $schema->getTable(self::TABLE);
		$changed = false;
		$hasUnique = false;

		foreach ($table->getIndexes() as $index) {
			if ($index->isPrimary() || $index->getColumns() !== [self::COLUMN]) {
				continue;
			}
			if ($index->isUnique()) {
				$hasUnique = true;
				continue;
			}
			// A plain index on the same column costs a write and adds nothing.
			$table->dropIndex($index->getName());
			$changed = true;
		}

		if (!$hasUnique) {
			$table->addUniqueIndex([self::COLUMN], self::UNIQUE_INDEX);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
