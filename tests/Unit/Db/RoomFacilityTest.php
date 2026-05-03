<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Db;

use OCA\SendentSynchroniser\Db\RoomFacility;
use PHPUnit\Framework\TestCase;

class RoomFacilityTest extends TestCase {
	public function testJsonSerialize(): void {
		$f = new RoomFacility();
		$f->setRoomId('boardroom-a');
		$f->setFacility('projector');

		$json = $f->jsonSerialize();
		$this->assertSame('boardroom-a', $json['roomId']);
		$this->assertSame('projector', $json['facility']);
	}
}
