<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Controller\RoomGroupApiController;
use OCA\SendentSynchroniser\Db\RoomGroup;
use OCA\SendentSynchroniser\Service\Room\RoomGroupService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RoomGroupApiControllerTest extends TestCase {

	private RoomGroupService&MockObject $svc;
	private RoomGroupApiController $ctrl;

	protected function setUp(): void {
		$this->svc = $this->createMock(RoomGroupService::class);
		$this->ctrl = new RoomGroupApiController('sendentsynchroniser', $this->createMock(IRequest::class), $this->svc);
	}

	public function testIndexReturnsEnvelopeWithDefaults(): void {
		$g = new RoomGroup(); $g->setId('exec'); $g->setName('Executive');
		$this->svc->expects($this->once())
			->method('listPage')
			->with(1, 30, null)
			->willReturn(['items' => [$g], 'total' => 1, 'page' => 1, 'perPage' => 30]);

		$resp = $this->ctrl->index();

		$this->assertSame(Http::STATUS_OK, $resp->getStatus());
		$data = $resp->getData();
		$this->assertSame('exec', $data['items'][0]['id']);
		$this->assertSame(1, $data['total']);
	}

	public function testIndexPassesQueryParams(): void {
		$this->svc->expects($this->once())
			->method('listPage')
			->with(2, 50, 'eng')
			->willReturn(['items' => [], 'total' => 0, 'page' => 2, 'perPage' => 50]);

		$this->ctrl->index(2, 50, 'eng');
	}

	public function testCreate(): void {
		$g = new RoomGroup(); $g->setId('exec'); $g->setName('Executive');
		$this->svc->method('create')->willReturn($g);
		$resp = $this->ctrl->create('exec', 'Executive');
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
	}
}
