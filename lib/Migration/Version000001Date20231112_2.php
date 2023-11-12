<?php

namespace OCA\SendentSynchroniser\migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000001Date20231112_2 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('sndntsync_license')) {
			// Adds a column to store the license used by a connected user
			$table = $schema->getTable('sndntsync_license');
			$table->addColumn('product', \OCP\DB\Types::STRING, [
				'notnull' => false
			]);
			$table->addColumn('technicallevel', \OCP\DB\Types::STRING, [
				'notnull' => false
			]);
			$table->addColumn('istrial', \OCP\DB\Types::INTEGER, [
				'notnull' => false
			]);
		}
		
		return $schema;
	}
}
