<?php

namespace OCA\SendentSynchroniser\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

class Version000003Date20260412 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('sndntsync_users')) {
			$table = $schema->getTable('sndntsync_users');

			if (!$table->hasColumn('calendar')) {
				$table->addColumn('calendar', 'string', [
					'notnull' => false,
					'default' => null,
					'length' => 255,
				]);
			}

			if (!$table->hasColumn('addressbook')) {
				$table->addColumn('addressbook', 'string', [
					'notnull' => false,
					'default' => null,
					'length' => 255,
				]);
			}
		}

		return $schema;
	}
}
