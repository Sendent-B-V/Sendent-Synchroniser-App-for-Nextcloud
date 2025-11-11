<?php

namespace OCA\SendentSynchroniser\migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

class Version000002Date20251020 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Add 'username' column to existing table
		if ($schema->hasTable('sndntsync_users')) {
			$table = $schema->getTable('sndntsync_users');

			if (!$table->hasColumn('username')) {
				$table->addColumn('username', 'string', [
					'notnull' => false,
					'default' => '',
					'length'  => 255,
				]);
			}
		}

		return $schema;
	}
}
