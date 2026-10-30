<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Controller\GraphAuthController;
use OCA\SendentSynchroniser\Service\StartLinkBuilder;
use OCA\SendentSynchroniser\Service\StartLinkUnavailableException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class GraphAuthControllerTest extends TestCase {

	private StartLinkBuilder&MockObject $builder;
	private IUserSession&Stub $userSession;
	private GraphAuthController $controller;

	protected function setUp(): void {
		$this->builder = $this->createMock(StartLinkBuilder::class);
		$this->userSession = $this->createStub(IUserSession::class);
		$this->controller = new GraphAuthController(
			'sendentsynchroniser',
			$this->createStub(IRequest::class),
			$this->builder,
			$this->userSession,
			new NullLogger(),
		);
	}

	private function loggedInAs(string $uid): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testRedirectsToTheBuiltLink(): void {
		$this->loggedInAs('alice');
		$this->builder->expects($this->once())->method('build')->with('alice')
			->willReturn('https://sync.example.com/auth/graph/start?user=alice');

		$response = $this->controller->start();

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('https://sync.example.com/auth/graph/start?user=alice', $response->getRedirectURL());
		$this->assertSame(Http::STATUS_SEE_OTHER, $response->getStatus());
	}

	public function testReturns409WithReasonWhenLinkUnavailable(): void {
		$this->loggedInAs('alice');
		$this->builder->expects($this->once())->method('build')
			->willThrowException(new StartLinkUnavailableException(StartLinkUnavailableException::REASON_PUBLIC_URL));

		$response = $this->controller->start();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['reason' => 'publicUrl'], $response->getData());
	}

	public function testReturns401WithoutUser(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->builder->expects($this->never())->method('build');

		$response = $this->controller->start();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}
}
