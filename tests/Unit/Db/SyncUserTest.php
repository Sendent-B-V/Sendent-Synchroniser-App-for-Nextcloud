<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Db;

use OCA\SendentSynchroniser\Db\SyncUser;
use PHPUnit\Framework\TestCase;

class SyncUserTest extends TestCase {

	public function testJsonSerialize(): void {
		$syncUser = new SyncUser();
		$syncUser->setUid('alice');
		$syncUser->setToken('encrypted-token');
		$syncUser->setActive(1);
		$syncUser->setUsername('alice.smith');
		$syncUser->setCalendar('exchange');
		$syncUser->setAddressbook('exchange-contacts');

		$json = $syncUser->jsonSerialize();

		$this->assertEquals('alice', $json['uid']);
		$this->assertEquals('encrypted-token', $json['token']);
		$this->assertEquals(1, $json['active']);
		$this->assertEquals('alice.smith', $json['username']);
		$this->assertEquals('exchange', $json['calendar']);
		$this->assertEquals('exchange-contacts', $json['addressbook']);
	}

	public function testCalendarDefaultsToNull(): void {
		$syncUser = new SyncUser();
		$syncUser->setUid('bob');

		$this->assertNull($syncUser->getCalendar());
		$this->assertNull($syncUser->getAddressbook());
	}
}
