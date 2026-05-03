<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Controller\RoomPermissionApiController;
use OCA\SendentSynchroniser\Db\RoomPermission;
use OCA\SendentSynchroniser\Service\Room\PermissionService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class RoomPermissionApiControllerTest extends TestCase {
	public function testGrantOnRoom(): void {
		$svc = $this->createMock(PermissionService::class);
		$p = new RoomPermission();
		$p->setId(1);
		$p->setRoomId('a');
		$p->setRole('booker');
		$p->setPrincipalType('user');
		$p->setPrincipalId('alice');
		$svc->expects($this->once())
			->method('grantOnRoom')
			->with('a', 'booker', 'user', 'alice')
			->willReturn($p);
		$ctrl = new RoomPermissionApiController('sendentsynchroniser', $this->createMock(IRequest::class), $svc);

		$resp = $ctrl->grantOnRoom('a', 'booker', 'user', 'alice');
		$this->assertSame(Http::STATUS_CREATED, $resp->getStatus());
	}
}
