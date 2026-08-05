<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Controller\CalendarResetController;
use OCA\SendentSynchroniser\Service\CalendarResetService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CalendarResetControllerTest extends TestCase {

	private CalendarResetService&MockObject $svc;
	private IUserSession&MockObject $userSession;
	private CalendarResetController $controller;

	protected function setUp(): void {
		$this->svc = $this->createMock(CalendarResetService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->controller = new CalendarResetController(
			'sendentsynchroniser',
			$this->createMock(IRequest::class),
			$this->svc,
			$this->userSession
		);
	}

	private function loginAs(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testStatusReportsApplicability(): void {
		$this->loginAs('alice');
		$this->svc->method('shouldOffer')->with('alice')->willReturn(true);
		$resp = $this->controller->status();
		$this->assertSame(['applicable' => true], $resp->getData());
	}

	public function testStatusReportsNotApplicable(): void {
		$this->loginAs('alice');
		$this->svc->method('shouldOffer')->with('alice')->willReturn(false);
		$resp = $this->controller->status();
		$this->assertSame(['applicable' => false], $resp->getData());
	}

	public function testStatusUnauthorizedWithoutUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$resp = $this->controller->status();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $resp->getStatus());
	}

	public function testExecuteRunsReset(): void {
		$this->loginAs('alice');
		$this->svc->expects($this->once())->method('reset')->with('alice')->willReturn(true);
		$resp = $this->controller->execute();
		$this->assertSame(['status' => 'OK'], $resp->getData());
	}

	public function testExecuteReportsSkippedWhenGuardRefuses(): void {
		$this->loginAs('alice');
		$this->svc->method('reset')->willReturn(false);
		$resp = $this->controller->execute();
		$this->assertSame(['status' => 'Skipped'], $resp->getData());
	}
}
