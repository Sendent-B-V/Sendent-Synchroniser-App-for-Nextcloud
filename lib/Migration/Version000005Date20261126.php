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
 * Adds the UID of the event to a per-event change row.
 *
 * The column can be empty. A row written before this migration has no UID,
 * and a row whose UID could not be read has none. Such rows are not filled
 * later.
 *
 * No index: no query selects on the UID. A second run changes nothing.
 */
class Version000005Date20261126 extends SimpleMigrationStep {

	private const TABLE = 'sndntsync_evt_changes';
	private const COLUMN = 'event_uid';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(self::TABLE)) {
			return null;
		}
		$table = $schema->getTable(self::TABLE);

		if (!$table->hasColumn(self::COLUMN)) {
			$table->addColumn(self::COLUMN, Types::STRING, [
				'length' => 255,
				'notnull' => false,
			]);
			return $schema;
		}
		return null;
	}
}
