<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit;

use OCA\SendentSynchroniser\Constants;
use PHPUnit\Framework\Attributes\DataProvider;
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

	public function testTokenNames(): void {
		$this->assertSame('sendent-synchronization', Constants::TOKEN_NAME);
		$this->assertSame('sendentsynchroniser', Constants::TOKEN_NAME_LEGACY);
	}

	public static function knownSignInModes(): array {
		return [
			'service account' => ['serviceAccount'],
			'per user, fallback' => ['perUserFallback'],
			'per user, strict' => ['perUserStrict'],
		];
	}

	#[DataProvider('knownSignInModes')]
	public function testKnownSignInModePassesThroughUnchanged(string $stored): void {
		$this->assertSame($stored, Constants::normaliseGraphAuthMethod($stored));
	}

	public static function unknownSignInModes(): array {
		return [
			'pre-rename value' => ['perUserOAuth'],
			'never set' => [''],
			'wrong case' => ['PerUserStrict'],
			'all lower case' => ['peruserfallback'],
			'surrounding space' => [' perUserStrict '],
			'free text' => ['delegated'],
		];
	}

	#[DataProvider('unknownSignInModes')]
	public function testUnknownSignInModeBecomesTheDefault(string $stored): void {
		// The sync service compares the mode byte for byte, so a near miss is
		// not repaired into the mode it resembles; it falls back like any
		// other unknown value.
		$this->assertSame('serviceAccount', Constants::normaliseGraphAuthMethod($stored));
	}

	public function testEveryAcceptedSignInModeSurvivesTheNormaliser(): void {
		// The setter accepts exactly this list; a mode it stores must read back
		// as itself.
		foreach (Constants::GRAPH_AUTH_METHODS as $mode) {
			$this->assertSame($mode, Constants::normaliseGraphAuthMethod($mode));
		}
		$this->assertContains(Constants::GRAPH_AUTH_METHOD_DEFAULT, Constants::GRAPH_AUTH_METHODS);
	}
}
