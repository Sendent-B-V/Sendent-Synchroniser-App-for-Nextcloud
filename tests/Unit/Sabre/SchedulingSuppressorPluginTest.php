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
use Sabre\DAV\Xml\Property\Href;
use Sabre\VObject\ITip\Message;

class SchedulingSuppressorPluginTest extends TestCase {

	private const SCHEDULE_PROP = '{urn:ietf:params:xml:ns:caldav}schedule-default-calendar-URL';

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

	/**
	 * Wire `Server::getProperties()` for the schedule-default-calendar-URL prop.
	 * Pass null to simulate "property not set".
	 */
	private function setSchedulePropResult(?string $value): void {
		$this->server->method('getProperties')
			->willReturn($value === null ? [] : [self::SCHEDULE_PROP => $value]);
	}

	public function testSuppressesWhenUserGatePassesAndUriCalendarMatchesPropertyDefault(): void {
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('calendars/alice/personal/1.ics');
		$this->setSchedulePropResult('calendars/alice/personal/');
		$this->suppressionService->method('shouldSuppress')->willReturn(true);

		$msg = new Message();
		$result = $this->plugin->onSchedule($msg);

		$this->assertFalse($result, 'must halt Sabre event propagation');
		$this->assertNotNull($msg->scheduleStatus);
		$this->assertStringStartsWith('2.0', $msg->scheduleStatus);
		$this->assertStringContainsString('suppressed by Sendent Sync (Disable ITip and IMip)', $msg->scheduleStatus);
	}

	public function testSuppressesWhenPropertyReturnsHrefObject(): void {
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('calendars/alice/personal/1.ics');
		$this->server->method('getProperties')->willReturn([
			self::SCHEDULE_PROP => new Href('calendars/alice/personal/'),
		]);
		$this->suppressionService->method('shouldSuppress')->willReturn(true);

		$msg = new Message();
		$this->assertFalse($this->plugin->onSchedule($msg));
	}

	public function testSuppressesWhenPropertyContainsFullRemotePhpDavUrl(): void {
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('calendars/alice/personal/1.ics');
		$this->setSchedulePropResult('/remote.php/dav/calendars/alice/personal/');
		$this->suppressionService->method('shouldSuppress')->willReturn(true);

		$msg = new Message();
		$this->assertFalse($this->plugin->onSchedule($msg));
	}

	public function testFallsThroughWhenUriCalendarIsNonDefault(): void {
		// User's default is `personal`, event is being PUT to `work` — pass through.
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('calendars/alice/work/1.ics');
		$this->setSchedulePropResult('calendars/alice/personal/');
		$this->suppressionService->method('shouldSuppress')->willReturn(true);

		$msg = new Message();
		$msg->scheduleStatus = '1.0;Pending';
		$result = $this->plugin->onSchedule($msg);

		$this->assertNull($result);
		$this->assertSame('1.0;Pending', $msg->scheduleStatus);
	}

	public function testFallsThroughOnOutboxRoute(): void {
		// Outbox POST: request URI is calendars/alice/outbox → segment "outbox"
		// is what we extract → compared to "personal" → no match → pass through.
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('calendars/alice/outbox');
		$this->setSchedulePropResult('calendars/alice/personal/');
		$this->suppressionService->method('shouldSuppress')->willReturn(true);

		$msg = new Message();
		$this->assertNull($this->plugin->onSchedule($msg));
	}

	public function testFallsThroughOnPrincipalRoute(): void {
		// Non-`calendars/...` paths extract to null → pass through.
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('principals/users/alice/');
		$this->setSchedulePropResult('calendars/alice/personal/');
		$this->suppressionService->method('shouldSuppress')->willReturn(true);

		$msg = new Message();
		$this->assertNull($this->plugin->onSchedule($msg));
	}

	public function testFallsThroughWhenPropertyIsUnset(): void {
		// Strict mode: no CalDAV property set means no default → don't suppress.
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('calendars/alice/personal/1.ics');
		$this->setSchedulePropResult(null);
		$this->suppressionService->method('shouldSuppress')->willReturn(true);

		$msg = new Message();
		$this->assertNull($this->plugin->onSchedule($msg));
	}

	public function testFallsThroughWhenUserGateFails(): void {
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('calendars/alice/personal/1.ics');
		$this->suppressionService->method('shouldSuppress')->willReturn(false);
		// User gate fails → property must not be queried at all
		$this->server->expects($this->never())->method('getProperties');

		$msg = new Message();
		$msg->scheduleStatus = '1.0;Pending';
		$result = $this->plugin->onSchedule($msg);

		$this->assertNull($result);
		$this->assertSame('1.0;Pending', $msg->scheduleStatus);
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

	public function testResolverIsMemoizedPerUidWithinRequest(): void {
		// A meeting with N attendees fires N schedule events. The plugin
		// must read the schedule-default-calendar-URL property ONCE per uid
		// across those events, not N times.
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('calendars/alice/personal/1.ics');
		$this->suppressionService->method('shouldSuppress')->willReturn(true);
		// Critical assertion: getProperties called exactly once across three onSchedule firings.
		$this->server->expects($this->once())
			->method('getProperties')
			->willReturn([self::SCHEDULE_PROP => 'calendars/alice/personal/']);

		$this->assertFalse($this->plugin->onSchedule(new Message()));
		$this->assertFalse($this->plugin->onSchedule(new Message()));
		$this->assertFalse($this->plugin->onSchedule(new Message()));
	}
}
