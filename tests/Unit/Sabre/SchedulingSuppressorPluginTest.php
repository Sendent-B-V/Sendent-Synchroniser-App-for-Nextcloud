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

	/**
	 * Wire Server::$tree->getNodeForPath() to return $node for any path.
	 * Pass null to make the lookup throw (node cannot be loaded).
	 */
	private function setTreeNode(?object $node): void {
		$tree = $this->createMock(\Sabre\DAV\Tree::class);
		if ($node === null) {
			$tree->method('getNodeForPath')
				->willThrowException(new \Sabre\DAV\Exception\NotFound('nope'));
		} else {
			$tree->method('getNodeForPath')->willReturn($node);
		}
		$this->server->tree = $tree;
	}

	/**
	 * Stand-in for NC's DeletedCalendarObject (a trash-bin node) exposing the
	 * event's origin calendar via getCalendarUri(). We duck-type, so the real
	 * NC class isn't needed here.
	 */
	private function trashNodeWithOriginCalendar(string $calendarUri): object {
		return new class($calendarUri) {
			public function __construct(private string $uri) {}
			public function getCalendarUri(): string {
				return $this->uri;
			}
		};
	}

	public function testSuppressesTrashbinPermanentDeleteWhenOriginCalendarIsDefault(): void {
		// Permanent delete from the recycle bin: request path is
		// calendars/alice/trashbin/{id}. The gate must resolve the trashed
		// object's ORIGIN calendar (personal) — matching the default — and suppress
		// the duplicate CANCEL that NC's beforeUnbind regenerates (server#36051).
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('calendars/alice/trashbin/42');
		$this->setSchedulePropResult('calendars/alice/personal/');
		$this->setTreeNode($this->trashNodeWithOriginCalendar('personal'));
		$this->suppressionService->method('shouldSuppress')->willReturn(true);

		$msg = new Message();
		$result = $this->plugin->onSchedule($msg);

		$this->assertFalse($result, 'must halt Sabre event propagation for trashbin default-calendar delete');
		$this->assertNotNull($msg->scheduleStatus);
		$this->assertStringContainsString('suppressed by Sendent Sync', $msg->scheduleStatus);
	}

	public function testFallsThroughTrashbinPermanentDeleteWhenOriginCalendarIsNonDefault(): void {
		// Trashed event originally lived in `work`, default is `personal` — its
		// move-to-trash CANCEL was never suppressed, so its purge CANCEL mustn't be either.
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('calendars/alice/trashbin/42');
		$this->setSchedulePropResult('calendars/alice/personal/');
		$this->setTreeNode($this->trashNodeWithOriginCalendar('work'));
		$this->suppressionService->method('shouldSuppress')->willReturn(true);

		$msg = new Message();
		$msg->scheduleStatus = '1.0;Pending';
		$result = $this->plugin->onSchedule($msg);

		$this->assertNull($result);
		$this->assertSame('1.0;Pending', $msg->scheduleStatus);
	}

	public function testFallsThroughTrashbinWhenOriginCalendarCannotBeResolved(): void {
		// Node isn't a recognisable trash object (no getCalendarUri) → we can't prove
		// it belonged to the default calendar → fail safe to NC's normal scheduling.
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('calendars/alice/trashbin/42');
		$this->setSchedulePropResult('calendars/alice/personal/');
		$this->setTreeNode(new \stdClass());
		$this->suppressionService->method('shouldSuppress')->willReturn(true);

		$msg = new Message();
		$this->assertNull($this->plugin->onSchedule($msg));
	}

	public function testFallsThroughTrashbinWhenNodeCannotBeLoaded(): void {
		// getNodeForPath throws (e.g. NotFound / concurrent purge) → resolver swallows
		// it and returns null → fail safe to NC's normal scheduling, no fatal.
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('calendars/alice/trashbin/42');
		$this->setSchedulePropResult('calendars/alice/personal/');
		$this->setTreeNode(null);
		$this->suppressionService->method('shouldSuppress')->willReturn(true);

		$msg = new Message();
		$this->assertNull($this->plugin->onSchedule($msg));
	}

	public function testSuppressesWhenDefaultCalendarUriIsLocalisedSlug(): void {
		// A non-English user's default calendar can carry a localized URI slug
		// (Dutch "persoonlijk", German "persönlich", …). We match URI-to-URI and
		// never touch the {DAV:}displayname, so suppression is locale-independent.
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('calendars/alice/persoonlijk/1.ics');
		$this->setSchedulePropResult('calendars/alice/persoonlijk/');
		$this->suppressionService->method('shouldSuppress')->willReturn(true);

		$msg = new Message();
		$this->assertFalse($this->plugin->onSchedule($msg), 'localized default-calendar slug must still suppress');
	}

	public function testSuppressesTrashbinPermanentDeleteWithLocalisedOriginCalendarUri(): void {
		// Same guarantee on the trashbin path: origin slug "persönlich" resolved
		// from the node matches the localized default slug → suppress.
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('calendars/alice/trashbin/42');
		$this->setSchedulePropResult('calendars/alice/persönlich/');
		$this->setTreeNode($this->trashNodeWithOriginCalendar('persönlich'));
		$this->suppressionService->method('shouldSuppress')->willReturn(true);

		$msg = new Message();
		$this->assertFalse($this->plugin->onSchedule($msg), 'localized trashbin origin slug must still suppress');
	}

	public function testSuppressesWhenPropertyHrefIsPercentEncodedNonAsciiSlug(): void {
		// NC may return the default-calendar href percent-encoded (server#40512)
		// while the request path is decoded. rawurldecode() normalises both so the
		// non-ASCII slug still matches and suppression holds.
		$this->userSession->method('getUser')->willReturn($this->userWithUid('alice'));
		$this->server->method('getRequestUri')->willReturn('calendars/alice/persönlich/1.ics');
		$this->setSchedulePropResult('calendars/alice/pers%C3%B6nlich/');
		$this->suppressionService->method('shouldSuppress')->willReturn(true);

		$msg = new Message();
		$this->assertFalse($this->plugin->onSchedule($msg), 'percent-encoded href must normalise and match');
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
