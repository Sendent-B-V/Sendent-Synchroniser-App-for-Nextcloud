<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\PrincipalAllowList;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PrincipalAllowListTest extends TestCase {

	/** @var IAppConfig&MockObject */
	private $appConfig;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var array<string, string> */
	private array $values = [];

	private PrincipalAllowList $list;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getAppValue')->willReturnCallback(
			fn (string $key, $default = '') => $this->values[$key] ?? $default
		);
		$this->appConfig->method('setAppValue')->willReturnCallback(
			function (string $key, string $value): void {
				$this->values[$key] = $value;
			}
		);
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->list = new PrincipalAllowList($this->appConfig, $this->config);
	}

	public function testDisabledListAllowsEveryPrincipal(): void {
		$this->config->method('allowListEnabled')->willReturn(false);

		$this->assertTrue($this->list->isAllowed('principals/users/anyone'));
	}

	public function testEnabledListAllowsOnlyListedPrincipals(): void {
		$this->config->method('allowListEnabled')->willReturn(true);
		$this->list->replace(['principals/users/alice', 'principals/users/bob']);

		$this->assertTrue($this->list->isAllowed('principals/users/alice'));
		$this->assertFalse($this->list->isAllowed('principals/users/mallory'));
	}

	public function testEnabledButEmptyListAllowsNothing(): void {
		$this->config->method('allowListEnabled')->willReturn(true);

		$this->assertFalse($this->list->isAllowed('principals/users/alice'));
	}

	public function testReplaceDeduplicatesAndDropsEmptyEntries(): void {
		$this->config->method('allowListEnabled')->willReturn(true);
		$this->list->replace(['principals/users/alice', 'principals/users/alice', '', 'principals/users/bob']);

		$this->assertSame(2, $this->list->count());
	}
}
