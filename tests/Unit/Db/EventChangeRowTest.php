<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Db;

use OCA\SendentSynchroniser\Db\EventChangeRow;
use PHPUnit\Framework\TestCase;

class EventChangeRowTest extends TestCase {

	public function testFromRowMapsEveryColumn(): void {
		// Integers arrive as text, as some database drivers give them.
		$row = EventChangeRow::fromRow([
			'id' => '7',
			'principal_uri' => 'principals/users/alice',
			'calendar_uri' => 'personal',
			'event_file_name' => 'one.ics',
			'kind' => 'updated',
			'sequence_number' => '1051',
			'written_time' => '1795000000',
		]);

		$this->assertSame(7, $row->getId());
		$this->assertSame('principals/users/alice', $row->getPrincipalUri());
		$this->assertSame('personal', $row->getCalendarUri());
		$this->assertSame('one.ics', $row->getEventFileName());
		$this->assertSame('updated', $row->getKind());
		$this->assertSame(1051, $row->getSequenceNumber());
		$this->assertSame(1795000000, $row->getWrittenTime());
	}

	public function testAPropertyMapsToItsColumnName(): void {
		$row = new EventChangeRow();

		$this->assertSame('event_file_name', $row->propertyToColumn('eventFileName'));
		$this->assertSame('sequence_number', $row->propertyToColumn('sequenceNumber'));
		$this->assertSame('written_time', $row->propertyToColumn('writtenTime'));
	}
}
