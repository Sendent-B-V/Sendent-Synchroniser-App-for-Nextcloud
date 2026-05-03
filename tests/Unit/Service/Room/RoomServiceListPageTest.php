<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\Room;

use OCA\SendentSynchroniser\Db\Room;
use OCA\SendentSynchroniser\Db\RoomBindingMapper;
use OCA\SendentSynchroniser\Db\RoomFacilityMapper;
use OCA\SendentSynchroniser\Db\RoomMapper;
use OCA\SendentSynchroniser\Db\RoomPermissionMapper;
use OCA\SendentSynchroniser\Service\Room\CalDAVService;
use OCA\SendentSynchroniser\Service\Room\HiddenUserService;
use OCA\SendentSynchroniser\Service\Room\RoomService;
use OCP\Calendar\Room\IManager as IRoomManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RoomServiceListPageTest extends TestCase {

	private RoomMapper&MockObject $rooms;
	private RoomService $svc;

	protected function setUp(): void {
		$this->rooms = $this->createMock(RoomMapper::class);
		$this->svc = new RoomService(
			$this->rooms,
			$this->createMock(RoomFacilityMapper::class),
			$this->createMock(RoomPermissionMapper::class),
			$this->createMock(RoomBindingMapper::class),
			$this->createMock(HiddenUserService::class),
			$this->createMock(CalDAVService::class),
			$this->createMock(IRoomManager::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testDefaultsAreUsedAndPassedToMapper(): void {
		$this->rooms->expects($this->once())->method('countAll')->with(null)->willReturn(0);
		$this->rooms->expects($this->once())->method('findPage')->with(1, 30, null)->willReturn([]);

		$result = $this->svc->listPage(1, 30, null);
		$this->assertSame(['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 30], $result);
	}

	public function testClampsPageBelowOneToOne(): void {
		$this->rooms->method('countAll')->willReturn(5);
		$this->rooms->expects($this->once())->method('findPage')->with(1, 30, null)->willReturn([]);

		$result = $this->svc->listPage(-3, 30, null);
		$this->assertSame(1, $result['page']);
	}

	public function testClampsPerPageBelowOneToOne(): void {
		$this->rooms->method('countAll')->willReturn(5);
		$this->rooms->expects($this->once())->method('findPage')->with(1, 1, null)->willReturn([]);

		$result = $this->svc->listPage(1, 0, null);
		$this->assertSame(1, $result['perPage']);
	}

	public function testClampsPerPageAboveHundredToHundred(): void {
		$this->rooms->method('countAll')->willReturn(5);
		$this->rooms->expects($this->once())->method('findPage')->with(1, 100, null)->willReturn([]);

		$result = $this->svc->listPage(1, 999, null);
		$this->assertSame(100, $result['perPage']);
	}

	public function testClampsPageBeyondLastToLast(): void {
		// total=137, perPage=30 → 5 pages. Asking for page 99 → page 5.
		$this->rooms->method('countAll')->willReturn(137);
		$this->rooms->expects($this->once())->method('findPage')->with(5, 30, null)->willReturn([]);

		$result = $this->svc->listPage(99, 30, null);
		$this->assertSame(5, $result['page']);
		$this->assertSame(137, $result['total']);
	}

	public function testEmptyResultReturnsPageOne(): void {
		$this->rooms->method('countAll')->willReturn(0);
		// page=1 even when caller asked for page=5, because total=0.
		$this->rooms->expects($this->once())->method('findPage')->with(1, 30, null)->willReturn([]);

		$result = $this->svc->listPage(5, 30, null);
		$this->assertSame(1, $result['page']);
		$this->assertSame([], $result['items']);
	}

	public function testQueryIsTrimmedAndForwarded(): void {
		$this->rooms->method('countAll')->with('board')->willReturn(2);
		$this->rooms->expects($this->once())->method('findPage')->with(1, 30, 'board')->willReturn([]);

		$result = $this->svc->listPage(1, 30, '  board  ');
		$this->assertSame(2, $result['total']);
	}
}
