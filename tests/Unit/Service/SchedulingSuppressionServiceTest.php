<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Service\SchedulingSuppressionService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SchedulingSuppressionServiceTest extends TestCase {

	/** @var IAppConfig&MockObject */
	private $appConfig;

	/** @var IGroupManager&MockObject */
	private $groupManager;

	private SchedulingSuppressionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->service = new SchedulingSuppressionService(
			$this->appConfig,
			$this->groupManager,
		);
	}

	/**
	 * Wire app-config returns for the suppression toggle + active groups.
	 *
	 * @param string $disableItipImip value to return for the suppression toggle key
	 * @param string $activeGroups    raw value to return for `activeGroups`
	 */
	private function setAppConfig(string $disableItipImip, string $activeGroups = ''): void {
		$this->appConfig->method('getAppValue')->willReturnCallback(
			function (string $key, $default = '') use ($disableItipImip, $activeGroups) {
				if ($key === Constants::DISABLE_ITIP_IMIP_KEY) {
					return $disableItipImip;
				}
				if ($key === 'activeGroups') {
					return $activeGroups;
				}
				return $default;
			}
		);
	}

	public function testReturnsFalseWhenDisableItipImipDisabled(): void {
		$this->setAppConfig('false', json_encode(['sendent']));
		$this->groupManager->expects($this->never())->method('isInGroup');

		$this->assertFalse($this->service->shouldSuppress('alice', 'calendars/alice/personal/1.ics'));
	}

	public function testReturnsFalseWhenUidIsNull(): void {
		$this->setAppConfig('true', json_encode(['sendent']));
		$this->groupManager->expects($this->never())->method('isInGroup');

		$this->assertFalse($this->service->shouldSuppress(null, 'calendars/alice/personal/1.ics'));
	}

	public function testReturnsFalseWhenUidIsEmptyString(): void {
		$this->setAppConfig('true', json_encode(['sendent']));
		$this->groupManager->expects($this->never())->method('isInGroup');

		$this->assertFalse($this->service->shouldSuppress('', 'calendars/alice/personal/1.ics'));
	}

	public function testReturnsFalseWhenActiveGroupsEmpty(): void {
		$this->setAppConfig('true', '');
		$this->groupManager->expects($this->never())->method('isInGroup');

		$this->assertFalse($this->service->shouldSuppress('alice', 'calendars/alice/personal/1.ics'));
	}

	public function testReturnsFalseWhenActiveGroupsLiteralNull(): void {
		$this->setAppConfig('true', 'null');
		$this->groupManager->expects($this->never())->method('isInGroup');

		$this->assertFalse($this->service->shouldSuppress('alice', 'calendars/alice/personal/1.ics'));
	}

	public function testReturnsFalseWhenActiveGroupsMalformedJson(): void {
		$this->setAppConfig('true', '{not valid');
		$this->groupManager->expects($this->never())->method('isInGroup');

		$this->assertFalse($this->service->shouldSuppress('alice', 'calendars/alice/personal/1.ics'));
	}

	public function testReturnsTrueWhenToggleOnAndUserInActiveGroup(): void {
		$this->setAppConfig('true', json_encode(['sendent']));
		$this->groupManager->method('isInGroup')->with('alice', 'sendent')->willReturn(true);

		$this->assertTrue($this->service->shouldSuppress('alice', 'calendars/alice/personal/1.ics'));
	}

	public function testReturnsFalseWhenUserIsInNoActiveGroup(): void {
		$this->setAppConfig('true', json_encode(['sendent']));
		$this->groupManager->method('isInGroup')->with('alice', 'sendent')->willReturn(false);

		$this->assertFalse($this->service->shouldSuppress('alice', 'calendars/alice/personal/1.ics'));
	}

	public function testReturnsTrueWhenUserIsInSecondOfManyActiveGroups(): void {
		$this->setAppConfig('true', json_encode(['execs', 'sendent', 'staff']));
		$this->groupManager->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $gid) => $uid === 'alice' && $gid === 'sendent'
		);

		$this->assertTrue($this->service->shouldSuppress('alice', 'calendars/alice/exchange/1.ics'));
	}

	public function testSuppressionIgnoresSyncUserRowAndStatus(): void {
		// Behavioral lock-in: even if the user has no SyncUser row, or has
		// status INACTIVE/NOCONSENT, group membership alone is enough to
		// trigger suppression. The service no longer consults SyncUserMapper.
		$this->setAppConfig('true', json_encode(['sendent']));
		$this->groupManager->method('isInGroup')->with('alice', 'sendent')->willReturn(true);

		$this->assertTrue($this->service->shouldSuppress('alice', 'calendars/alice/personal/1.ics'));
	}

	public function testSuppressesRegardlessOfRequestPath(): void {
		// Request path is no longer consulted; every shape must yield true
		// once toggle + group-membership gates pass.
		$this->setAppConfig('true', json_encode(['sendent']));
		$this->groupManager->method('isInGroup')->with('alice', 'sendent')->willReturn(true);

		$this->assertTrue($this->service->shouldSuppress('alice', 'calendars/alice/personal/1.ics'));
		$this->assertTrue($this->service->shouldSuppress('alice', 'calendars/alice/exchange/1.ics'));
		$this->assertTrue($this->service->shouldSuppress('alice', 'calendars/alice/personal-1/1.ics'));
		$this->assertTrue($this->service->shouldSuppress('alice', '/calendars/alice/exchange/1.ics'));
		$this->assertTrue($this->service->shouldSuppress('alice', 'principals/users/alice/'));
		$this->assertTrue($this->service->shouldSuppress('alice', ''));
	}

	public function testIgnoresNonStringEntriesInActiveGroups(): void {
		$this->setAppConfig('true', json_encode(['sendent', 42, null, ['nested']]));
		$this->groupManager->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $gid) => $gid === 'sendent'
		);

		$this->assertTrue($this->service->shouldSuppress('alice', 'calendars/alice/exchange/1.ics'));
	}
}
