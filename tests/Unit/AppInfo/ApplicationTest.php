<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\AppInfo;

use OCA\SendentSynchroniser\AppInfo\Application;
use OCA\SendentSynchroniser\Listener\DavChangeListener;
use OCA\SendentSynchroniser\Listener\UserGoneListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase {

	/** @return list<string> the events the listener was registered for */
	private function changeEventsRegistered(bool $serverSupported, string $listenerClass = DavChangeListener::class): array {
		$app = new class($serverSupported) extends Application {
			public function __construct(private bool $supported) {
				parent::__construct();
			}

			protected function changeNotificationsSupported(): bool {
				return $this->supported;
			}
		};

		$events = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener) use (&$events, $listenerClass): void {
				if ($listener === $listenerClass) {
					$events[] = $event;
				}
			}
		);

		$app->register($context);

		return $events;
	}

	public function testAServerWithTheChangeEventsRecordsCalendarsAndAddressBooks(): void {
		$events = $this->changeEventsRegistered(true);

		$this->assertContains(\OCP\Calendar\Events\CalendarObjectUpdatedEvent::class, $events);
		$this->assertContains(\OCA\DAV\Events\CardUpdatedEvent::class, $events);
	}

	public function testTheSubscriptionEventsAreNeverRegistered(): void {
		// A subscription is not a user calendar. Its events must not reach
		// the change feed.
		foreach ($this->changeEventsRegistered(true) as $event) {
			$this->assertStringNotContainsString('CachedCalendarObject', $event);
		}
	}

	public function testAServerWithoutTheCalendarObjectEventsRecordsNothing(): void {
		// Recording only contacts and collection changes would let the
		// Connector rely on a feed that silently misses calendar edits. On
		// NC 28/29 it would also run nested transactions without savepoints,
		// where a failed ledger write fails the user's own DAV write.
		$this->assertSame([], $this->changeEventsRegistered(false));
	}

	public function testADeletedUserAndAUserRemovedFromAGroupReachTheUserGoneListener(): void {
		$events = $this->changeEventsRegistered(true, UserGoneListener::class);

		$this->assertContains(\OCP\User\Events\UserDeletedEvent::class, $events);
		$this->assertContains(\OCP\Group\Events\UserRemovedEvent::class, $events);
		$this->assertCount(2, $events);
	}
}
