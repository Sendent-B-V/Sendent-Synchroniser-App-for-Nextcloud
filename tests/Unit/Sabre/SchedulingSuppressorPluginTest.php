<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Sabre;

use OCA\SendentSynchroniser\Sabre\SchedulingSuppressorPlugin;
use OCA\SendentSynchroniser\Service\SchedulingSuppressionService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sabre\DAV\Server;
use Sabre\VObject\ITip\Message;

class SchedulingSuppressorPluginTest extends TestCase {

	/** @var SchedulingSuppressionService&MockObject */
	private $suppressionService;

	/** @var IUserSession&MockObject */
	private $userSession;

	/** @var Server&MockObject */
	private $server;

	private SchedulingSuppressorPlugin $plugin;

	protected function setUp(): void {
		parent::setUp();
		$this->suppressionService = $this->createMock(SchedulingSuppressionService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->server = $this->createMock(Server::class);
		$this->plugin = new SchedulingSuppressorPlugin(
			$this->suppressionService,
			$this->userSession,
			new NullLogger(),
		);
		$this->plugin->initialize($this->server);
	}

	private function userWithUid(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	public function testReturnsFalseAndSetsStatusWhenServiceSuppresses(): void {
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('calendars/alice/exchange/1.ics');
		$this->suppressionService->method('shouldSuppress')
			->with('alice', 'calendars/alice/exchange/1.ics')
			->willReturn(true);

		$msg = new Message();
		$result = $this->plugin->onSchedule($msg);

		$this->assertFalse($result, 'must halt Sabre event propagation');
		$this->assertNotNull($msg->scheduleStatus);
		$this->assertStringStartsWith('2.0', $msg->scheduleStatus);
		$this->assertStringContainsString('suppressed by Sendent Sync (Graph API mode)', $msg->scheduleStatus);
	}

	public function testReturnsNullAndLeavesStatusWhenServiceDoesNotSuppress(): void {
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('calendars/alice/personal/1.ics');
		$this->suppressionService->method('shouldSuppress')->willReturn(false);

		$msg = new Message();
		$msg->scheduleStatus = '1.0;Pending';
		$result = $this->plugin->onSchedule($msg);

		$this->assertNull($result, 'must allow Sabre event propagation');
		$this->assertSame('1.0;Pending', $msg->scheduleStatus, 'status must be left untouched');
	}

	public function testPassesNullUidToServiceWhenNoUserInSession(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$this->server->method('getRequestUri')->willReturn('calendars/alice/exchange/1.ics');
		$this->suppressionService->expects($this->once())
			->method('shouldSuppress')
			->with(null, 'calendars/alice/exchange/1.ics')
			->willReturn(false);

		$msg = new Message();
		$result = $this->plugin->onSchedule($msg);

		$this->assertNull($result);
		$this->assertNull($msg->scheduleStatus);
	}
}
