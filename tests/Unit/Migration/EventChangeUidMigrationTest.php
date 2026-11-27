<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\StringType;
use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\SendentSynchroniser\Migration\Version000005Date20261120;
use OCA\SendentSynchroniser\Migration\Version000005Date20261124;
use OCA\SendentSynchroniser\Migration\Version000005Date20261126;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use PHPUnit\Framework\TestCase;

/**
 * {@see Version000005Date20261126} adds the UID column to the per-event table.
 *
 * The tests run the migrations against a Doctrine schema, behind the real
 * Nextcloud schema wrapper. They prove the definition of the column. They do
 * not prove what a database does with the rows that are already there; the
 * integration test does.
 */
class EventChangeUidMigrationTest extends TestCase {

	private const PREFIX = 'oc_';
	private const COLUMN = 'event_uid';

	/** Runs one migration and says if it reported a change. */
	private function migrate(SimpleMigrationStep $migration, Schema $schema): bool {
		$connection = $this->createStub(Connection::class);
		$connection->method('getPrefix')->willReturn(self::PREFIX);
		$wrapper = new SchemaWrapper($connection, $schema);

		return $migration->changeSchema($this->createStub(IOutput::class), fn () => $wrapper, []) !== null;
	}

	/** The schema of an instance that has the table and the unique index. */
	private function schemaBeforeTheUid(): Schema {
		$schema = new Schema();
		$this->migrate(new Version000005Date20261120(), $schema);
		$this->migrate(new Version000005Date20261124(), $schema);

		return $schema;
	}

	private function tableOf(Schema $schema): Table {
		$tables = array_values($schema->getTables());
		$this->assertCount(1, $tables);

		return $tables[0];
	}

	/** @return list<string> */
	private function columnNames(Table $table): array {
		$names = array_map(static fn ($column): string => $column->getName(), array_values($table->getColumns()));
		sort($names);

		return $names;
	}

	/** @return array<string, list<string>> index name => its columns */
	private function indexes(Table $table): array {
		$indexes = [];
		foreach ($table->getIndexes() as $index) {
			/** @var Index $index */
			$indexes[$index->getName()] = $index->getColumns();
		}
		ksort($indexes);

		return $indexes;
	}

	public function testTheTableHasNoUidColumnBeforeTheMigration(): void {
		$this->assertFalse($this->tableOf($this->schemaBeforeTheUid())->hasColumn(self::COLUMN));
	}

	public function testTheMigrationAddsTheColumn(): void {
		$schema = $this->schemaBeforeTheUid();

		$this->assertTrue($this->migrate(new Version000005Date20261126(), $schema), 'the migration must report a change');
		$this->assertTrue($this->tableOf($schema)->hasColumn(self::COLUMN));
	}

	public function testTheColumnCanBeEmptyAndHasNoDefaultText(): void {
		// The rows that are already there get no value. An empty text as the
		// default would give them a UID of ''.
		$schema = $this->schemaBeforeTheUid();
		$this->migrate(new Version000005Date20261126(), $schema);

		$this->assertTrue($this->tableOf($schema)->hasColumn(self::COLUMN));
		$column = $this->tableOf($schema)->getColumn(self::COLUMN);
		$this->assertFalse($column->getNotnull(), 'the column must permit NULL');
		$this->assertNull($column->getDefault(), 'the column must have no default value');
	}

	public function testTheColumnIsATextOf255Characters(): void {
		// A UID is text. 255 is the length of the uid column of Nextcloud.
		$schema = $this->schemaBeforeTheUid();
		$this->migrate(new Version000005Date20261126(), $schema);

		$this->assertTrue($this->tableOf($schema)->hasColumn(self::COLUMN));
		$column = $this->tableOf($schema)->getColumn(self::COLUMN);
		$this->assertInstanceOf(StringType::class, $column->getType());
		$this->assertSame(255, $column->getLength());
	}

	public function testTheOtherColumnsAndTheIndexesStay(): void {
		$schema = $this->schemaBeforeTheUid();
		$columnsBefore = $this->columnNames($this->tableOf($schema));
		$indexesBefore = $this->indexes($this->tableOf($schema));

		$this->migrate(new Version000005Date20261126(), $schema);

		$table = $this->tableOf($schema);
		$this->assertSame([self::COLUMN], array_values(array_diff($this->columnNames($table), $columnsBefore)));
		$this->assertSame([], array_diff($columnsBefore, $this->columnNames($table)));
		$this->assertSame($indexesBefore, $this->indexes($table), 'no index is added, removed or changed');
	}

	public function testASecondRunChangesNothing(): void {
		$schema = $this->schemaBeforeTheUid();
		$this->assertTrue($this->migrate(new Version000005Date20261126(), $schema));
		$columns = $this->columnNames($this->tableOf($schema));

		$this->assertFalse($this->migrate(new Version000005Date20261126(), $schema));
		$this->assertSame($columns, $this->columnNames($this->tableOf($schema)));
	}

	public function testDoesNothingWhenTheTableIsNotThere(): void {
		$schema = new Schema();

		$this->assertFalse($this->migrate(new Version000005Date20261126(), $schema));
		$this->assertCount(0, $schema->getTables());
	}
}
