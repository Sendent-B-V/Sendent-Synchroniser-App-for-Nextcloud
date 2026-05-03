<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Db;

use OCA\SendentSynchroniser\Db\RoomPermission;
use PHPUnit\Framework\TestCase;

class RoomPermissionTest extends TestCase {
	public function testJsonSerialize(): void {
		$p = new RoomPermission();
		$p->setRoomId('boardroom-a');
		$p->setRole('booker');
		$p->setPrincipalType('user');
		$p->setPrincipalId('alice');

		$json = $p->jsonSerialize();
		$this->assertSame('boardroom-a', $json['roomId']);
		$this->assertNull($json['groupId']);
		$this->assertSame('booker', $json['role']);
		$this->assertSame('user', $json['principalType']);
		$this->assertSame('alice', $json['principalId']);
	}
}
