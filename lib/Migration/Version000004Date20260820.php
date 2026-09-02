<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Change-notification ledger.
 *
 * sndntsync_dirty holds one row per DAV collection, upserted on every change,
 * so the table is bounded by the number of collections on the instance
 * rather than by event volume — no pruning is needed.
 *
 * The sequence column is called change_seq because CURSOR is a reserved word
 * in MySQL 8 and PostgreSQL.
 */
class Version000004Date20260820 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('sndntsync_dirty')) {
			$table = $schema->createTable('sndntsync_dirty');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('principal_uri', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('collection_type', Types::STRING, [
				'notnull' => true,
				'length' => 8,
			]);
			$table->addColumn('collection_uri', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('sync_token', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 20,
			]);
			$table->addColumn('change_seq', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 20,
			]);
			// Sequence of the last structural (collection-level) change; the feed reports c = structural_seq > since.
			$table->addColumn('structural_seq', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 20,
			]);
			$table->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 20,
			]);

			$table->setPrimaryKey(['id']);
			// 255 + 8 + 255 chars = 2072 bytes under utf8mb4, inside InnoDB's
			// 3072-byte index limit with DYNAMIC row format.
			$table->addUniqueIndex(
				['principal_uri', 'collection_type', 'collection_uri'],
				'sndntsync_dirty_coll_uq'
			);
			$table->addIndex(['change_seq'], 'sndntsync_dirty_seq_ix');
		}

		if (!$schema->hasTable('sndntsync_seq')) {
			$table = $schema->createTable('sndntsync_seq');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('stamp', Types::BIGINT, [
				'notnull' => true,
				'default' => 0,
				'length' => 20,
			]);

			$table->setPrimaryKey(['id']);
		}

		return $schema;
	}
}
