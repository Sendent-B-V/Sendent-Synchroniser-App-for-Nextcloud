<?php

  namespace OCA\SendentSynchroniser\migration;

  use Closure;
  use OCP\DB\ISchemaWrapper;
  use OCP\Migration\SimpleMigrationStep;
  use OCP\Migration\IOutput;

  class Version000001Date20230918 extends SimpleMigrationStep {
  	/**
  	 * @param IOutput $output
  	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
  	 * @param array $options
  	 * @return null|ISchemaWrapper
  	 */
  	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
  		/** @var ISchemaWrapper $schema */
  		$schema = $schemaClosure();

		$table = $schema->getTable('sndntsync_users');
		$table->addColumn('email', 'string', [
			'notnull' => false,
		]);

  		return $schema;
  	}
  }
