<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\Room;

use OCA\SendentSynchroniser\Db\Room;
use OCA\SendentSynchroniser\Db\RoomBinding;
use OCA\SendentSynchroniser\Db\RoomBindingMapper;
use OCA\SendentSynchroniser\Db\RoomMapper;
use OCA\SendentSynchroniser\Service\LicenseManager;
use OCA\SendentSynchroniser\Service\Room\Binding\BindingKindRegistry;
use OCA\SendentSynchroniser\Service\Room\Binding\BindingValidator;
use OCA\SendentSynchroniser\Service\Room\Binding\LicenseRequiredException;
use OCA\SendentSynchroniser\Service\Room\BindingService;
use OCA\SendentSynchroniser\Service\Room\RoomValidationException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BindingServiceTest extends TestCase {

	private RoomBindingMapper&MockObject $bindings;
	private RoomMapper&MockObject $rooms;
	private BindingKindRegistry&MockObject $registry;
	private LicenseManager&MockObject $license;
	private BindingService $svc;

	protected function setUp(): void {
		$this->bindings = $this->createMock(RoomBindingMapper::class);
		$this->rooms = $this->createMock(RoomMapper::class);
		$this->registry = $this->createMock(BindingKindRegistry::class);
		$this->license = $this->createMock(LicenseManager::class);
		$this->svc = new BindingService($this->bindings, $this->rooms, $this->registry, $this->license);
	}

	public function testSetThrowsWithoutLicense(): void {
		$this->license->method('hasEntitlement')->with('rooms.sync')->willReturn(false);
		$this->expectException(LicenseRequiredException::class);
		$this->svc->set('boardroom-a', 'exchange', 'boardroom-a@contoso.com', []);
	}

	public function testSetRejectsUnknownKind(): void {
		$this->license->method('hasEntitlement')->willReturn(true);
		$this->rooms->method('findById')->willReturn(new Room());
		$this->registry->method('get')->with('martian')->willReturn(null);
		$this->expectException(RoomValidationException::class);
		$this->svc->set('boardroom-a', 'martian', 'whatever', []);
	}

	public function testSetCreatesBindingWithLinkVersion1WhenAbsent(): void {
		$this->license->method('hasEntitlement')->willReturn(true);
		$this->rooms->method('findById')->willReturn(new Room());
		$validator = $this->createMock(BindingValidator::class);
		$validator->method('validate')->willReturn(['externalId' => 'x@y.z', 'config' => []]);
		$this->registry->method('get')->willReturn($validator);
		$this->bindings->method('findByRoomIdOrNull')->willReturn(null);

		$this->bindings->expects($this->once())->method('insert')->willReturnArgument(0);

		$b = $this->svc->set('boardroom-a', 'exchange', 'X@y.z', []);
		$this->assertSame(1, $b->getLinkVersion());
		$this->assertSame(RoomBinding::STATE_PENDING, $b->getState());
		$this->assertTrue($b->getInitialSyncRequested());
	}

	public function testSetIncrementsLinkVersionWhenChanged(): void {
		$this->license->method('hasEntitlement')->willReturn(true);
		$this->rooms->method('findById')->willReturn(new Room());
		$validator = $this->createMock(BindingValidator::class);
		$validator->method('validate')->willReturn(['externalId' => 'new@y.z', 'config' => []]);
		$this->registry->method('get')->willReturn($validator);

		$existing = new RoomBinding();
		$existing->setLinkVersion(3);
		$existing->setExternalId('old@y.z');
		$existing->setKind('exchange');
		$existing->setConfig('{}');
		$this->bindings->method('findByRoomIdOrNull')->willReturn($existing);

		$this->bindings->expects($this->once())->method('update')->willReturnArgument(0);
		$b = $this->svc->set('boardroom-a', 'exchange', 'new@y.z', []);
		$this->assertSame(4, $b->getLinkVersion());
		$this->assertTrue($b->getInitialSyncRequested());
	}

	public function testClearDoesNotCheckLicense(): void {
		$this->bindings->expects($this->once())->method('deleteByRoomId')->with('boardroom-a');
		$this->svc->clear('boardroom-a');
	}

	public function testRetryBumpsVersionRequiresExistingBinding(): void {
		$existing = new RoomBinding();
		$existing->setLinkVersion(2);
		$this->bindings->method('findByRoomId')->with('boardroom-a')->willReturn($existing);
		$this->bindings->expects($this->once())->method('update');

		$b = $this->svc->retry('boardroom-a');
		$this->assertSame(3, $b->getLinkVersion());
		$this->assertSame(RoomBinding::STATE_PENDING, $b->getState());
		$this->assertTrue($b->getInitialSyncRequested());
	}
}
