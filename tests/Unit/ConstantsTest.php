<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit;

use OCA\SendentSynchroniser\Constants;
use PHPUnit\Framework\TestCase;

class ConstantsTest extends TestCase {

	public function testUserStatusValues(): void {
		$this->assertEquals(0, Constants::USER_STATUS_INACTIVE);
		$this->assertEquals(1, Constants::USER_STATUS_ACTIVE);
		$this->assertEquals(2, Constants::USER_STATUS_NOCONSENT);
	}

	public function testReminderDefaults(): void {
		$this->assertEquals(Constants::REMINDER_NOTIFICATIONS, Constants::REMINDER_DEFAULT_TYPE);
		$this->assertEquals(7, Constants::REMINDER_NOTIFICATIONS_DEFAULT_INTERVAL);
	}
}
