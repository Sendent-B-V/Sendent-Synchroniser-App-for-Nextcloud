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

  		if (!$schema->hasTable('sndnt_srvacc')) {
  			$table = $schema->createTable('sndnt_srvacc');
  			$table->addColumn('id', 'integer', [
  				'autoincrement' => true,
  				'notnull' => true,
  			]);


  			$table->addColumn('username', 'string', [
  				'notnull' => false,
  				'length' => 254,
  			]);
  			$table->setPrimaryKey(['id']);
  			$table->addUniqueIndex(['username'], 'sendent_srvacc_unique_index');
  		}
		
		if (!$schema->hasTable('sndnt_syncgrp')) {
			$table = $schema->createTable('sndnt_syncgrp');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);


			$table->addColumn('name', 'string', [
				'notnull' => false,
				'length' => 254,
			]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['name'], 'sendent_syncgrp_unique_index');
		}

		if (!$schema->hasTable('sndnt_syncusr')) {
			$table = $schema->createTable('sndnt_syncusr');
			$table->addColumn('id', 'integer', [
				'autoincrement' => true,
				'notnull' => true,
			]);

			$table->addColumn('username', 'string', [
				'notnull' => false,
				'length' => 254,
			]);
			$table->addColumn('groupId', 'string', [
				'notnull' => false,
				'length' => 254,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['username', 'groupId'], 'sendent_syncusr_unique_index');
		}

  		return $schema;
  	}
  }
