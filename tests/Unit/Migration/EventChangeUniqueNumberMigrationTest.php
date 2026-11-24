<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\SendentSynchroniser\Migration\Version000005Date20261120;
use OCA\SendentSynchroniser\Migration\Version000005Date20261124;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use PHPUnit\Framework\TestCase;

/**
 * {@see Version000005Date20261124} makes the sequence number unique.
 *
 * The tests run the migrations against a Doctrine schema, behind the real
 * Nextcloud schema wrapper. They prove that the index is there and unique.
 * They do not prove that a database refuses a duplicate row.
 */
class EventChangeUniqueNumberMigrationTest extends TestCase {

	private const PREFIX = 'oc_';
	private const COLUMN = 'sequence_number';
	/** Index name limit of Nextcloud 32, the oldest version the app supports. */
	private const MAX_INDEX_NAME = 30;

	/** Runs one migration and says if it reported a change. */
	private function migrate(SimpleMigrationStep $migration, Schema $schema): bool {
		$connection = $this->createStub(Connection::class);
		$connection->method('getPrefix')->willReturn(self::PREFIX);
		$wrapper = new SchemaWrapper($connection, $schema);

		return $migration->changeSchema($this->createStub(IOutput::class), fn () => $wrapper, []) !== null;
	}

	/** The schema of an instance that ran the table migration only. */
	private function schemaWithTheTable(): Schema {
		$schema = new Schema();
		$this->migrate(new Version000005Date20261120(), $schema);

		return $schema;
	}

	private function tableOf(Schema $schema): Table {
		$tables = array_values($schema->getTables());
		$this->assertCount(1, $tables);

		return $tables[0];
	}

	/**
	 * @return list<Index> the indexes on the sequence number alone
	 */
	private function numberIndexes(Table $table): array {
		return array_values(array_filter(
			$table->getIndexes(),
			fn (Index $index): bool => !$index->isPrimary() && $index->getColumns() === [self::COLUMN]
		));
	}

	/**
	 * @return list<string>
	 */
	private function indexNames(Table $table): array {
		$names = array_map(fn (Index $index): string => $index->getName(), array_values($table->getIndexes()));
		sort($names);

		return $names;
	}

	public function testTheTableMigrationAloneGivesAPlainIndex(): void {
		// The start state of the instances that already have the table.
		$indexes = $this->numberIndexes($this->tableOf($this->schemaWithTheTable()));

		$this->assertCount(1, $indexes);
		$this->assertFalse($indexes[0]->isUnique());
	}

	public function testTheNumberHasOneIndexAndItIsUnique(): void {
		$schema = $this->schemaWithTheTable();

		$this->assertTrue($this->migrate(new Version000005Date20261124(), $schema));

		$indexes = $this->numberIndexes($this->tableOf($schema));
		$this->assertCount(1, $indexes, 'the unique index must replace the plain index, not join it');
		$this->assertTrue($indexes[0]->isUnique());
	}

	public function testTheOtherIndexesStay(): void {
		$schema = $this->schemaWithTheTable();
		$before = $this->tableOf($schema);
		$others = array_diff($this->indexNames($before), [$this->numberIndexes($before)[0]->getName()]);
		$this->assertNotEmpty($others);

		$this->migrate(new Version000005Date20261124(), $schema);

		$this->assertSame([], array_diff($others, $this->indexNames($this->tableOf($schema))));
	}

	public function testASecondRunChangesNothing(): void {
		$schema = $this->schemaWithTheTable();
		$this->migrate(new Version000005Date20261124(), $schema);
		$names = $this->indexNames($this->tableOf($schema));

		$this->assertFalse($this->migrate(new Version000005Date20261124(), $schema));
		$this->assertSame($names, $this->indexNames($this->tableOf($schema)));
	}

	public function testDoesNothingWhenTheTableIsNotThere(): void {
		$schema = new Schema();

		$this->assertFalse($this->migrate(new Version000005Date20261124(), $schema));
		$this->assertCount(0, $schema->getTables());
	}

	public function testTheIndexNameIsInsideTheNextcloudLimit(): void {
		$schema = $this->schemaWithTheTable();
		$this->migrate(new Version000005Date20261124(), $schema);

		$name = $this->numberIndexes($this->tableOf($schema))[0]->getName();
		$this->assertLessThanOrEqual(self::MAX_INDEX_NAME, strlen($name), "index name \"$name\" is too long");
	}
}
