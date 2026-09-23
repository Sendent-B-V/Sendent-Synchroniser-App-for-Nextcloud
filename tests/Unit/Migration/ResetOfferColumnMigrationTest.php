<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\SendentSynchroniser\Migration\Version000004Date20260908;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\ITable;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Schema half of the 2.1.0 migration {@see Version000004Date20260908}: adds the
 * `resetoffer` column to sndntsync_users exactly once.
 */
class ResetOfferColumnMigrationTest extends TestCase {

	private function step(): Version000004Date20260908 {
		return new Version000004Date20260908($this->createMock(IDBConnection::class));
	}

	/**
	 * NC 35 wraps schema objects in OCP\DB\Schema\ITable and declares it as the
	 * return type of ISchemaWrapper::getTable(); NC 34 and older hand out the
	 * Doctrine Table
	 */
	private function tableMock(): MockObject {
		return $this->createMock(interface_exists(ITable::class) ? ITable::class : Table::class);
	}

	public function testAddsResetOfferColumnWhenMissing(): void {
		$table = $this->tableMock();
		$table->method('hasColumn')->with('resetoffer')->willReturn(false);
		$table->expects($this->once())->method('addColumn')
			->with('resetoffer', 'smallint', ['notnull' => true, 'default' => 0]);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('sndntsync_users')->willReturn(true);
		$schema->method('getTable')->with('sndntsync_users')->willReturn($table);

		$result = $this->step()->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);
		$this->assertSame($schema, $result);
	}

	public function testDoesNotAddColumnTwice(): void {
		$table = $this->tableMock();
		$table->method('hasColumn')->with('resetoffer')->willReturn(true);
		$table->expects($this->never())->method('addColumn');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('sndntsync_users')->willReturn(true);
		$schema->method('getTable')->with('sndntsync_users')->willReturn($table);

		$this->step()->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);
	}

	public function testLeavesSchemaUntouchedWhenTableMissing(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('sndntsync_users')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$result = $this->step()->changeSchema($this->createMock(IOutput::class), fn () => $schema, []);
		$this->assertSame($schema, $result);
	}
}
