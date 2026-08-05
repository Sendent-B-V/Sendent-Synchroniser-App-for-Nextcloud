<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Listener;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\SendentSynchroniser\Listener\CalendarObjectTrashScrubListener;
use OCA\SendentSynchroniser\Service\TrashbinScrubService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class CalendarObjectTrashScrubListenerTest extends TestCase {

	private const ICS_WITH_PROPS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:1\r\nX-SENDENT-ID:abc\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
	private const ICS_STRIPPED = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:1\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

	/** @var TrashbinScrubService&MockObject */
	private $scrubService;

	/** @var CalDavBackend&MockObject */
	private $backend;

	private CalendarObjectTrashScrubListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$this->scrubService = $this->createMock(TrashbinScrubService::class);
		$this->backend = $this->createMock(CalDavBackend::class);
		$this->listener = new CalendarObjectTrashScrubListener(
			$this->scrubService,
			$this->backend,
			new NullLogger(),
		);
	}

	/**
	 * The move-to-trash event moved namespaces in NC 32 (the OCA\DAV class was
	 * removed). Instantiate whichever flavour exists on the server checkout the
	 * tests run against — which also means the CI matrix exercises the OCA path
	 * on NC <= 31 legs and the OCP path on NC 32+ legs, mirroring production.
	 * Both share the constructor (int, array, array, array) and getters.
	 */
	private function movedToTrashEvent(
		int $calendarId = 42,
		string $calendarUri = 'personal',
		string $objectUri = 'event.ics',
		mixed $calendarData = self::ICS_WITH_PROPS,
	): Event {
		$class = class_exists(\OCA\DAV\Events\CalendarObjectMovedToTrashEvent::class)
			? \OCA\DAV\Events\CalendarObjectMovedToTrashEvent::class
			: \OCP\Calendar\Events\CalendarObjectMovedToTrashEvent::class;

		return new $class(
			$calendarId,
			['id' => $calendarId, 'uri' => $calendarUri, 'principaluri' => 'principals/users/alice'],
			[],
			['uri' => $objectUri, 'calendardata' => $calendarData],
		);
	}

	public function testIgnoresUnrelatedEvents(): void {
		$this->scrubService->expects($this->never())->method('isEnabled');
		$this->backend->expects($this->never())->method('updateCalendarObject');

		$this->listener->handle(new class extends Event {
		});
	}

	public function testDoesNothingWhenToggleDisabled(): void {
		$this->scrubService->method('isEnabled')->willReturn(false);
		$this->scrubService->expects($this->never())->method('stripSendentProperties');
		$this->backend->expects($this->never())->method('updateCalendarObject');

		$this->listener->handle($this->movedToTrashEvent());
	}

	public function testDoesNothingForNonPersonalCalendar(): void {
		$this->scrubService->method('isEnabled')->willReturn(true);
		$this->scrubService->expects($this->never())->method('stripSendentProperties');
		$this->backend->expects($this->never())->method('updateCalendarObject');

		$this->listener->handle($this->movedToTrashEvent(42, 'exchange-work'));
	}

	public function testScrubsPersonalCalendarObjectAtTrashedUri(): void {
		$this->scrubService->method('isEnabled')->willReturn(true);
		$this->scrubService->method('stripSendentProperties')
			->with(self::ICS_WITH_PROPS)
			->willReturn(self::ICS_STRIPPED);

		// Write-back must target the RENAMED row: event.ics -> event-deleted.ics
		$this->backend->expects($this->once())
			->method('updateCalendarObject')
			->with(42, 'event-deleted.ics', self::ICS_STRIPPED);

		$this->listener->handle($this->movedToTrashEvent());
	}

	public function testSkipsWriteWhenNothingToStrip(): void {
		$this->scrubService->method('isEnabled')->willReturn(true);
		$this->scrubService->method('stripSendentProperties')->willReturn(null);
		$this->backend->expects($this->never())->method('updateCalendarObject');

		$this->listener->handle($this->movedToTrashEvent());
	}

	public function testSecondDispatchForSameObjectIsANoOp(): void {
		// NC 31 dispatches both the OCP and the legacy OCA event for one delete.
		$this->scrubService->method('isEnabled')->willReturn(true);
		$this->scrubService->method('stripSendentProperties')->willReturn(self::ICS_STRIPPED);
		$this->backend->expects($this->once())->method('updateCalendarObject');

		$this->listener->handle($this->movedToTrashEvent());
		$this->listener->handle($this->movedToTrashEvent());
	}

	public function testDistinctObjectsAreEachScrubbed(): void {
		$this->scrubService->method('isEnabled')->willReturn(true);
		$this->scrubService->method('stripSendentProperties')->willReturn(self::ICS_STRIPPED);
		$this->backend->expects($this->exactly(2))->method('updateCalendarObject');

		$this->listener->handle($this->movedToTrashEvent(42, 'personal', 'a.ics'));
		$this->listener->handle($this->movedToTrashEvent(42, 'personal', 'b.ics'));
	}

	public function testReadsCalendarDataFromStreamResource(): void {
		$stream = fopen('php://memory', 'r+');
		fwrite($stream, self::ICS_WITH_PROPS);
		rewind($stream);

		$this->scrubService->method('isEnabled')->willReturn(true);
		$this->scrubService->method('stripSendentProperties')
			->with(self::ICS_WITH_PROPS)
			->willReturn(self::ICS_STRIPPED);
		$this->backend->expects($this->once())
			->method('updateCalendarObject')
			->with(42, 'event-deleted.ics', self::ICS_STRIPPED);

		$this->listener->handle($this->movedToTrashEvent(42, 'personal', 'event.ics', $stream));
	}

	public function testUriWithoutExtensionGetsBareDeletedSuffix(): void {
		$this->scrubService->method('isEnabled')->willReturn(true);
		$this->scrubService->method('stripSendentProperties')->willReturn(self::ICS_STRIPPED);
		$this->backend->expects($this->once())
			->method('updateCalendarObject')
			->with(42, 'event-deleted', self::ICS_STRIPPED);

		$this->listener->handle($this->movedToTrashEvent(42, 'personal', 'event'));
	}

	public function testNeverThrowsWhenBackendFails(): void {
		// The event fires inside the soft-delete transaction: an escaping
		// exception would roll back the user's delete.
		$this->scrubService->method('isEnabled')->willReturn(true);
		$this->scrubService->method('stripSendentProperties')->willReturn(self::ICS_STRIPPED);
		$this->backend->method('updateCalendarObject')
			->willThrowException(new \RuntimeException('db gone'));

		$this->listener->handle($this->movedToTrashEvent());
		$this->addToAssertionCount(1);
	}

	public function testNeverThrowsWhenStripFails(): void {
		$this->scrubService->method('isEnabled')->willReturn(true);
		$this->scrubService->method('stripSendentProperties')
			->willThrowException(new \Sabre\VObject\ParseException('bad ics'));
		$this->backend->expects($this->never())->method('updateCalendarObject');

		$this->listener->handle($this->movedToTrashEvent());
		$this->addToAssertionCount(1);
	}

	public function testSkipsObjectRowWithoutCalendarData(): void {
		$this->scrubService->method('isEnabled')->willReturn(true);
		$this->scrubService->expects($this->never())->method('stripSendentProperties');
		$this->backend->expects($this->never())->method('updateCalendarObject');

		$this->listener->handle($this->movedToTrashEvent(42, 'personal', 'event.ics', null));
	}
}
