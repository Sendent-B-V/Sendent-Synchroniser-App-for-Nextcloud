<?php

  namespace OCA\SendentSynchroniser\migration;

  use Closure;
  use OCP\DB\ISchemaWrapper;
  use OCP\Migration\SimpleMigrationStep;
  use OCP\Migration\IOutput;

  class Version000001Date20230430 extends SimpleMigrationStep {
  	/**
  	 * @param IOutput $output
  	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
  	 * @param array $options
  	 * @return null|ISchemaWrapper
  	 */
  	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
  		/** @var ISchemaWrapper $schema */
  		$schema = $schemaClosure();

		if (!$schema->hasTable('sndntsync_users')) {
			$table = $schema->createTable('sndntsync_users');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);

			$table->addColumn('uid', 'string', [
				'notnull' => true,
				'default' => '',
				'length' => 64,
			]);
			$table->addColumn('token', 'text', [
				'notnull' => true,
				'default' => '',
			]);
			$table->addColumn('active', 'smallint', [
				'notnull' => true,
				'default' => 0,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['uid'],'sendentsync_uid_idx');
		}

  		return $schema;
  	}
  }
