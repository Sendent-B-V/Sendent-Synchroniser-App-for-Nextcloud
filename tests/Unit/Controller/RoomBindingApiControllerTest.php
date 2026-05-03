<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Controller\RoomBindingApiController;
use OCA\SendentSynchroniser\Db\RoomBinding;
use OCA\SendentSynchroniser\Service\Room\Binding\BindingValidationException;
use OCA\SendentSynchroniser\Service\Room\Binding\LicenseRequiredException;
use OCA\SendentSynchroniser\Service\Room\BindingService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RoomBindingApiControllerTest extends TestCase {

	private BindingService&MockObject $svc;
	private RoomBindingApiController $ctrl;

	protected function setUp(): void {
		$this->svc = $this->createMock(BindingService::class);
		$this->ctrl = new RoomBindingApiController('sendentsynchroniser', $this->createMock(IRequest::class), $this->svc);
	}

	public function testPutReturnsBindingOn200(): void {
		$b = new RoomBinding();
		$b->setRoomId('a');
		$b->setKind('exchange');
		$b->setExternalId('a@b.c');
		$b->setLinkVersion(1);
		$b->setState('pending');
		$b->setInitialSyncRequested(true);
		$b->setLastEventsPushed(0);
		$b->setLastEventsPulled(0);
		$this->svc->method('set')->willReturn($b);
		$resp = $this->ctrl->put('a', 'exchange', 'a@b.c', []);
		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
	}

	public function testPutReturns402OnLicenseRequired(): void {
		$this->svc->method('set')->willThrowException(new LicenseRequiredException('locked'));
		$resp = $this->ctrl->put('a', 'exchange', 'a@b.c', []);
		$this->assertSame(402, $resp->getStatus());
		$this->assertSame('license_required', $resp->getData()['error']['code']);
	}

	public function testPutReturns400OnValidation(): void {
		$this->svc->method('set')->willThrowException(new BindingValidationException('bad email'));
		$resp = $this->ctrl->put('a', 'exchange', 'bad', []);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	public function testDeleteReturns204(): void {
		$this->svc->expects($this->once())->method('clear')->with('a');
		$resp = $this->ctrl->delete('a');
		$this->assertSame(Http::STATUS_NO_CONTENT, $resp->getStatus());
	}

	public function testStatusReturns204OnApply(): void {
		$this->svc->method('applyStatus')->willReturn(true);
		$resp = $this->ctrl->status('boardroom-a', 3, 'completed', '2026-05-02T10:15:00Z', null, false, 5, 2);
		$this->assertSame(Http::STATUS_NO_CONTENT, $resp->getStatus());
	}

	public function testStatusReturns404WhenBindingGone(): void {
		$this->svc->method('applyStatus')->willReturn(false);
		$resp = $this->ctrl->status('gone', 1, 'completed', '2026-05-02T10:15:00Z', null, null, null, null);
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
	}

	public function testStatusReturns400OnBadState(): void {
		$resp = $this->ctrl->status('a', 1, 'wizard', null, null, null, null, null);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	public function testStatusReturns400WhenFailedWithoutError(): void {
		$resp = $this->ctrl->status('a', 1, 'failed', null, null, null, null, null);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	public function testStatusReturns400WhenCompletedWithoutTimestamp(): void {
		$resp = $this->ctrl->status('a', 1, 'completed', null, null, null, null, null);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}
}
