<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Controller\RoomApiController;
use OCA\SendentSynchroniser\Db\Room;
use OCA\SendentSynchroniser\Service\Room\RoomService;
use OCA\SendentSynchroniser\Service\Room\RoomValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RoomApiControllerTest extends TestCase {

	private RoomService&MockObject $svc;
	private IRequest&MockObject $request;
	private RoomApiController $ctrl;

	protected function setUp(): void {
		$this->svc = $this->createMock(RoomService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->ctrl = new RoomApiController('sendentsynchroniser', $this->request, $this->svc);
	}

	public function testIndexReturnsEnvelopeWithDefaults(): void {
		$r = new Room(); $r->setId('a'); $r->setName('A');
		$this->svc->expects($this->once())
			->method('listPage')
			->with(1, 30, null)
			->willReturn(['items' => [$r], 'total' => 1, 'page' => 1, 'perPage' => 30]);

		$resp = $this->ctrl->index();

		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$data = $resp->getData();
		$this->assertSame('a', $data['items'][0]['id']);
		$this->assertSame(1, $data['page']);
		$this->assertSame(30, $data['perPage']);
		$this->assertSame(1, $data['total']);
	}

	public function testIndexPassesQueryParams(): void {
		$this->svc->expects($this->once())
			->method('listPage')
			->with(2, 50, 'board')
			->willReturn(['items' => [], 'total' => 0, 'page' => 2, 'perPage' => 50]);

		$this->ctrl->index(2, 50, 'board');
	}

	public function testCreateReturns201(): void {
		$r = new Room(); $r->setId('a'); $r->setName('A');
		$this->svc->method('create')->willReturn($r);
		$resp = $this->ctrl->create('a', 'A');
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
	}

	public function testCreateReturns400OnValidationError(): void {
		$this->svc->method('create')->willThrowException(new RoomValidationException('bad id'));
		$resp = $this->ctrl->create('BAD', 'A');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $resp->getStatus());
	}

	public function testShowReturns404OnDoesNotExist(): void {
		$this->svc->method('get')->willThrowException(new DoesNotExistException('x'));
		$resp = $this->ctrl->show('x');
		$this->assertSame(Http::STATUS_NOT_FOUND, $resp->getStatus());
	}
}
