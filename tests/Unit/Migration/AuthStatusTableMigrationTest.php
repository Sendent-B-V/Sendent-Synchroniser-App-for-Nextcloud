<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use OCA\SendentSynchroniser\Migration\Version000005Date20261030;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\IColumn;
use OCP\DB\Schema\ITable;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * {@see Version000005Date20261030}: creates sndntsync_authstatus exactly once,
 * keyed by uid plus provider.
 */
class AuthStatusTableMigrationTest extends TestCase {

	private const TABLE = 'sndntsync_authstatus';

	private function step(): Version000005Date20261030 {
		return new Version000005Date20261030();
	}

	/** See ResetOfferColumnMigrationTest for why the interface is picked at runtime. */
	private function tableMock(): MockObject {
		return $this->createMock(interface_exists(ITable::class) ? ITable::class : Table::class);
	}

	/** Same split for what addColumn must return. */
	private function columnStub(): Stub {
		return $this->createStub(interface_exists(IColumn::class) ? IColumn::class : Column::class);
	}

	public function testCreatesTableWithColumnsAndUniqueKeyWhenMissing(): void {
		$table = $this->tableMock();
		$columns = [];
		$table->expects($this->exactly(6))->method('addColumn')
			->willReturnCallback(function (string $name, string $type, array $options) use (&$columns) {
				$columns[$name] = [$type, $options];
				return $this->columnStub();
			});
		$table->expects($this->once())->method('setPrimaryKey')->with(['id']);
		$table->expects($this->once())->method('addUniqueIndex')
			->with(['uid', 'provider'], 'sndntsync_authstatus_uidprov');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with(self::TABLE)->willReturn(false);
		$schema->expects($this->once())->method('createTable')->with(self::TABLE)->willReturn($table);

		$result = $this->step()->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);

		$this->assertSame($schema, $result);
		$this->assertSame(['id', 'uid', 'provider', 'status', 'reason', 'changed_at'], array_keys($columns));
		$this->assertSame('bigint', $columns['id'][0]);
		$this->assertTrue($columns['id'][1]['autoincrement']);
		// uid must hold whatever sndntsync_users.uid holds.
		$this->assertSame(64, $columns['uid'][1]['length']);
		$this->assertSame('bigint', $columns['changed_at'][0]);
		// reason is the only nullable column: it only travels with needsReconnect.
		foreach (['id', 'uid', 'provider', 'status', 'changed_at'] as $notNull) {
			$this->assertTrue($columns[$notNull][1]['notnull'], "$notNull must be not-null");
		}
		$this->assertFalse($columns['reason'][1]['notnull']);
	}

	public function testIndexNameFitsTheOracleLimitOnOlderNextcloud(): void {
		// Nextcloud 28 to 33: identifier length at most 27 plus the table prefix (3 for oc_).
		$table = $this->tableMock();
		$table->method('addColumn')->willReturn($this->columnStub());
		$table->expects($this->once())->method('addUniqueIndex')
			->with($this->anything(), $this->callback(function (string $name) {
				$this->assertLessThanOrEqual(30, strlen($name), "index name '$name' too long for Nextcloud 28");
				return true;
			}));

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->method('createTable')->willReturn($table);

		$this->step()->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);
	}

	public function testLeavesExistingTableAlone(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with(self::TABLE)->willReturn(true);
		$schema->expects($this->never())->method('createTable');

		$result = $this->step()->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);
		$this->assertSame($schema, $result);
	}
}
