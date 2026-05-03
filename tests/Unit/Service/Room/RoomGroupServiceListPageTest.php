<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\Room;

use OCA\SendentSynchroniser\Db\RoomGroupMapper;
use OCA\SendentSynchroniser\Db\RoomMapper;
use OCA\SendentSynchroniser\Db\RoomPermissionMapper;
use OCA\SendentSynchroniser\Service\Room\RoomGroupService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RoomGroupServiceListPageTest extends TestCase {

	private RoomGroupMapper&MockObject $groups;
	private RoomGroupService $svc;

	protected function setUp(): void {
		$this->groups = $this->createMock(RoomGroupMapper::class);
		$this->svc = new RoomGroupService(
			$this->groups,
			$this->createMock(RoomMapper::class),
			$this->createMock(RoomPermissionMapper::class),
		);
	}

	public function testDefaults(): void {
		$this->groups->expects($this->once())->method('countAll')->with(null)->willReturn(0);
		$this->groups->expects($this->once())->method('findPage')->with(1, 30, null)->willReturn([]);

		$this->assertSame(
			['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 30],
			$this->svc->listPage(1, 30, null),
		);
	}

	public function testClampsPerPage(): void {
		$this->groups->method('countAll')->willReturn(5);
		$this->groups->expects($this->once())->method('findPage')->with(1, 100, null);

		$this->svc->listPage(1, 9999, null);
	}

	public function testClampsPageBeyondLast(): void {
		$this->groups->method('countAll')->willReturn(45);
		// 45 / 30 = ceil 2 pages.
		$this->groups->expects($this->once())->method('findPage')->with(2, 30, null)->willReturn([]);

		$result = $this->svc->listPage(7, 30, null);
		$this->assertSame(2, $result['page']);
	}

	public function testQueryTrim(): void {
		$this->groups->method('countAll')->with('eng')->willReturn(1);
		$this->groups->expects($this->once())->method('findPage')->with(1, 30, 'eng');

		$this->svc->listPage(1, 30, '  eng ');
	}
}
