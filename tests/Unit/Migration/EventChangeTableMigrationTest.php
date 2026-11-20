<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\SendentSynchroniser\Migration\Version000005Date20261120;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Properties of the table that {@see Version000005Date20261120} creates.
 *
 * The tests do not know the name of the table, of a column or of an index.
 * They run the migration against an empty Doctrine schema, behind the real
 * Nextcloud schema wrapper, and then examine what the migration built.
 */
class EventChangeTableMigrationTest extends TestCase {

	private const PREFIX = 'oc_';

	/**
	 * Name limits of Nextcloud 32, the oldest version the app supports
	 * (ensureOracleConstraints() in lib/private/DB/MigrationService.php on
	 * stable32). Nextcloud 33 and later allow 63 characters for each
	 * (ensureNamingConstraints() in the same file).
	 */
	private const MAX_TABLE_NAME = 27;
	private const MAX_COLUMN_NAME = 30;
	private const MAX_INDEX_NAME = 30;
	/** Longest table name for which Nextcloud 32 accepts a primary key without its own name. */
	private const MAX_TABLE_NAME_DEFAULT_PRIMARY = 22;

	/**
	 * Largest index key InnoDB accepts with the DYNAMIC row format and the
	 * default 16 KB page. This number is from the MySQL manual ("InnoDB
	 * Limits"), not from the Nextcloud source.
	 */
	private const INNODB_MAX_KEY_BYTES = 3072;
	private const UTF8MB4_BYTES_PER_CHAR = 4;
	/** Length Doctrine gives a string column that has none. */
	private const DEFAULT_STRING_LENGTH = 255;

	/** Upper bound of the key size in bytes, for each column type with a fixed size. */
	private const FIXED_KEY_BYTES = [
		'bigint' => 8,
		'integer' => 4,
		'smallint' => 2,
		'boolean' => 1,
		'datetime' => 8,
		'datetime_immutable' => 8,
		'datetimetz' => 8,
		'datetimetz_immutable' => 8,
		'date' => 3,
		'date_immutable' => 3,
		'time' => 6,
		'time_immutable' => 6,
	];

	/** Types that hold text of no fixed size; an index must give a prefix length for them. */
	private const UNBOUNDED_TYPES = ['text', 'blob', 'json'];

	/**
	 * Runs the migration against $schema and returns that schema.
	 */
	private function migrate(Schema $schema): Schema {
		$connection = $this->createMock(Connection::class);
		$connection->method('getPrefix')->willReturn(self::PREFIX);
		$wrapper = new SchemaWrapper($connection, $schema);

		$result = (new Version000005Date20261120())
			->changeSchema($this->createMock(IOutput::class), fn () => $wrapper, []);

		$this->assertSame($wrapper, $result, 'changeSchema() must return the schema it was given');

		return $schema;
	}

	private function newTable(): Table {
		$tables = array_values($this->migrate(new Schema())->getTables());
		$this->assertCount(1, $tables, 'the migration must create exactly one table');

		return $tables[0];
	}

	private function nameWithoutPrefix(Table $table): string {
		return substr($table->getName(), strlen(self::PREFIX));
	}

	/**
	 * @return Column[]
	 */
	private function columnsOf(Table $table): array {
		$columns = $table->getColumns();
		$this->assertNotEmpty($columns, 'the table has no columns yet');

		return $columns;
	}

	public function testTableExistsAfterTheMigration(): void {
		$table = $this->newTable();

		$this->assertStringStartsWith(self::PREFIX, $table->getName());
		$this->assertNotSame('', $this->nameWithoutPrefix($table));
	}

	public function testTableHasAPrimaryKey(): void {
		$primary = $this->newTable()->getPrimaryKey();

		$this->assertNotNull($primary, 'the table has no primary key');
		$this->assertNotEmpty($primary->getColumns());
	}

	public function testChangesNothingWhenTheTableAlreadyExists(): void {
		$name = $this->newTable()->getName();

		$schema = new Schema();
		$existing = $schema->createTable($name);
		$existing->addColumn('marker_only', 'integer');
		$existing->setPrimaryKey(['marker_only']);

		$this->migrate($schema);

		$this->assertCount(1, $schema->getTables());
		$this->assertSame(['marker_only'], array_keys($existing->getColumns()));
		$this->assertCount(1, $existing->getIndexes());
	}

	public function testTableNameIsInsideTheNextcloudLimit(): void {
		$name = $this->nameWithoutPrefix($this->newTable());

		$this->assertLessThanOrEqual(self::MAX_TABLE_NAME, strlen($name), "table name \"$name\" is too long");
	}

	public function testColumnNamesAreInsideTheNextcloudLimit(): void {
		foreach ($this->columnsOf($this->newTable()) as $column) {
			$name = $column->getName();
			$this->assertLessThanOrEqual(self::MAX_COLUMN_NAME, strlen($name), "column name \"$name\" is too long");
		}
	}

	public function testIndexNamesAreInsideTheNextcloudLimit(): void {
		$table = $this->newTable();
		$indexes = $table->getIndexes();
		$this->assertNotEmpty($indexes, 'the table has no index yet, not even a primary key');

		foreach ($indexes as $index) {
			$name = $index->getName();

			if ($index->isPrimary() && strtolower($name) === 'primary') {
				// No own name: the database derives one from the table name,
				// so Nextcloud 32 limits the table name instead.
				$this->assertLessThanOrEqual(
					self::MAX_TABLE_NAME_DEFAULT_PRIMARY,
					strlen($this->nameWithoutPrefix($table)),
					'the table name is too long for a primary key without its own name'
				);
				continue;
			}

			$this->assertLessThanOrEqual(self::MAX_INDEX_NAME, strlen($name), "index name \"$name\" is too long");
		}
	}

	public function testNoColumnNameIsAReservedWord(): void {
		$platforms = [
			'MySQL 8' => new MySQL80Platform(),
			'MariaDB' => new MariaDBPlatform(),
			'PostgreSQL' => new PostgreSQLPlatform(),
		];

		foreach ($this->columnsOf($this->newTable()) as $column) {
			$name = $column->getName();
			foreach ($platforms as $label => $platform) {
				$this->assertFalse(
					$platform->getReservedKeywordsList()->isKeyword($name),
					"column name \"$name\" is a reserved word in $label"
				);
			}
		}
	}

	public function testEveryIndexIsInsideTheInnoDbSizeLimit(): void {
		$table = $this->newTable();
		$indexes = $table->getIndexes();
		$this->assertNotEmpty($indexes, 'the table has no index yet, not even a primary key');

		foreach ($indexes as $index) {
			$prefixLengths = $index->hasOption('lengths') ? (array)$index->getOption('lengths') : [];

			$bytes = 0;
			foreach (array_values($index->getColumns()) as $position => $columnName) {
				$bytes += $this->keyBytes($table->getColumn($columnName), $prefixLengths[$position] ?? null);
			}

			$this->assertLessThanOrEqual(
				self::INNODB_MAX_KEY_BYTES,
				$bytes,
				sprintf('index "%s" needs %d bytes under utf8mb4', $index->getName(), $bytes)
			);
		}
	}

	/**
	 * Upper bound of the bytes one column adds to an InnoDB index key under utf8mb4.
	 */
	private function keyBytes(Column $column, ?int $prefixLength): int {
		$type = Type::lookupName($column->getType());

		if (isset(self::FIXED_KEY_BYTES[$type])) {
			return self::FIXED_KEY_BYTES[$type];
		}

		if ($type === 'string') {
			$chars = $column->getLength() ?? self::DEFAULT_STRING_LENGTH;
			if ($prefixLength !== null) {
				$chars = min($chars, $prefixLength);
			}
			return $chars * self::UTF8MB4_BYTES_PER_CHAR;
		}

		if (in_array($type, self::UNBOUNDED_TYPES, true)) {
			$this->assertNotNull(
				$prefixLength,
				sprintf('column "%s" has type %s; MySQL cannot index it without a prefix length', $column->getName(), $type)
			);
			return $prefixLength * self::UTF8MB4_BYTES_PER_CHAR;
		}

		$this->fail(sprintf(
			'column "%s" has type %s, for which this test knows no key size; add it to FIXED_KEY_BYTES',
			$column->getName(),
			$type
		));
	}
}
