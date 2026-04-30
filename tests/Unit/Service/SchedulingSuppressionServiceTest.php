<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Db\SyncUser;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCA\SendentSynchroniser\Service\SchedulingSuppressionService;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SchedulingSuppressionServiceTest extends TestCase {

	/** @var IAppConfig&MockObject */
	private $appConfig;

	/** @var SyncUserMapper&MockObject */
	private $syncUserMapper;

	private SchedulingSuppressionService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->syncUserMapper = $this->createMock(SyncUserMapper::class);
		$this->service = new SchedulingSuppressionService(
			$this->appConfig,
			$this->syncUserMapper,
		);
	}

	private function syncUser(string $uid, int $active, string $calendar): SyncUser {
		$u = new SyncUser();
		$u->setUid($uid);
		$u->setActive($active);
		$u->setCalendar($calendar);
		return $u;
	}

	private function setGraphApiMode(string $value): void {
		$this->appConfig->method('getAppValue')
			->with(Constants::GRAPH_API_MODE_KEY, Constants::GRAPH_API_MODE_DEFAULT)
			->willReturn($value);
	}

	public function testReturnsFalseWhenGraphApiModeDisabled(): void {
		$this->setGraphApiMode('false');
		$this->syncUserMapper->expects($this->never())->method('findByUid');

		$this->assertFalse($this->service->shouldSuppress('alice', 'calendars/alice/exchange/1.ics'));
	}

	public function testReturnsFalseWhenUidIsNull(): void {
		$this->setGraphApiMode('true');
		$this->syncUserMapper->expects($this->never())->method('findByUid');

		$this->assertFalse($this->service->shouldSuppress(null, 'calendars/alice/exchange/1.ics'));
	}

	public function testReturnsFalseWhenSyncUserMissing(): void {
		$this->setGraphApiMode('true');
		$this->syncUserMapper->method('findByUid')->with('alice')->willReturn([]);

		$this->assertFalse($this->service->shouldSuppress('alice', 'calendars/alice/exchange/1.ics'));
	}

	public function testReturnsFalseWhenSyncUserInactive(): void {
		$this->setGraphApiMode('true');
		$this->syncUserMapper->method('findByUid')->with('alice')
			->willReturn([$this->syncUser('alice', 0, 'exchange')]);

		$this->assertFalse($this->service->shouldSuppress('alice', 'calendars/alice/exchange/1.ics'));
	}

	public function testReturnsFalseWhenCalendarSegmentDoesNotMatch(): void {
		$this->setGraphApiMode('true');
		$this->syncUserMapper->method('findByUid')->with('alice')
			->willReturn([$this->syncUser('alice', 1, 'exchange')]);

		$this->assertFalse($this->service->shouldSuppress('alice', 'calendars/alice/personal/1.ics'));
	}

	public function testReturnsFalseWhenPathIsNotACalendarPath(): void {
		$this->setGraphApiMode('true');
		$this->syncUserMapper->method('findByUid')->with('alice')
			->willReturn([$this->syncUser('alice', 1, 'exchange')]);

		$this->assertFalse($this->service->shouldSuppress('alice', 'principals/users/alice/'));
		$this->assertFalse($this->service->shouldSuppress('alice', 'calendars/alice'));
		$this->assertFalse($this->service->shouldSuppress('alice', ''));
	}

	public function testReturnsTrueWhenAllConditionsHold(): void {
		$this->setGraphApiMode('true');
		$this->syncUserMapper->method('findByUid')->with('alice')
			->willReturn([$this->syncUser('alice', 1, 'exchange')]);

		$this->assertTrue($this->service->shouldSuppress('alice', 'calendars/alice/exchange/event-1.ics'));
	}

	public function testReturnsTrueWithLeadingSlashOnPath(): void {
		$this->setGraphApiMode('true');
		$this->syncUserMapper->method('findByUid')->with('alice')
			->willReturn([$this->syncUser('alice', 1, 'exchange')]);

		$this->assertTrue($this->service->shouldSuppress('alice', '/calendars/alice/exchange/event-1.ics'));
	}
}
