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
 * Adds an index on the principal of a per-event change row.
 *
 * The rows of one user are removed when the user is deleted, leaves the
 * sync users or is invalidated. A group change can do this for many users
 * in one request. Without the index, each removal reads the full table.
 *
 * A second run changes nothing.
 */
class Version000005Date20261127 extends SimpleMigrationStep {

	private const TABLE = 'sndntsync_evt_changes';
	private const COLUMN = 'principal_uri';
	private const INDEX = 'sndntsync_evt_changes_prin_ix';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable(self::TABLE)) {
			return null;
		}
		if ($schema->getTable(self::TABLE)->hasIndex(self::INDEX)) {
			return null;
		}
		$schema->getTable(self::TABLE)->addIndex([self::COLUMN], self::INDEX);
		
		return $schema;
	}
}
