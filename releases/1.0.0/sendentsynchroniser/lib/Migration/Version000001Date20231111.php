<?php

namespace OCA\SendentSynchroniser\migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000001Date20231111 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		//new since licensing feature - connecteduser
		if (!$schema->hasTable('sndntsync_connusr')) {
			$table = $schema->createTable('sndntsync_connusr');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => false,
			]);

			$table->addColumn('userid', 'string', [
				'notnull' => false
			]);
			$table->addColumn('dateconnected', 'string', [
				'notnull' => false
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['userid'], 'sendentsync_connuserid_index');
		}
		return $schema;
	}
}
