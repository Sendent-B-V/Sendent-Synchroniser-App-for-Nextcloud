<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\SendentSynchroniser\Migration\Version000004Date20260908;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version000004Date20260908Test extends TestCase {

	private function step(): Version000004Date20260908 {
		return new Version000004Date20260908($this->createMock(IDBConnection::class));
	}

	public function testAddsResetOfferColumnWhenMissing(): void {
		$table = $this->createMock(Table::class);
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
		$table = $this->createMock(Table::class);
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
