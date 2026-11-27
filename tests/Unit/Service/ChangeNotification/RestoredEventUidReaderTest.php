<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\SendentSynchroniser\Service\ChangeNotification\RestoredEventUidReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The backend rows used here have the shape of CalDavBackend's
 * getCalendarObject().
 */
class RestoredEventUidReaderTest extends TestCase {

	/** @var CalDavBackend&MockObject */
	private $backend;

	/** @var LoggerInterface&MockObject */
	private $logger;

	private RestoredEventUidReader $reader;

	protected function setUp(): void {
		parent::setUp();
		$this->backend = $this->createMock(CalDavBackend::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->reader = new RestoredEventUidReader($this->backend, $this->logger);
	}

	/** The short row of a restore event: no id and no uid. */
	private static function restoredRow(string $uri = 'meeting.ics'): array {
		return [
			'calendardata' => "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n",
			'uri' => $uri,
			'calendarid' => 42,
			'calendartype' => 0,
			'component' => 'VEVENT',
		];
	}

	/** @return array<string, mixed> */
	private static function backendRow(mixed $uid): array {
		return [
			'id' => 1001,
			'uri' => 'meeting.ics',
			'uid' => $uid,
			'calendarid' => 42,
			'calendardata' => "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n",
		];
	}

	public function testGivesTheUidOfTheEventTheBackendFinds(): void {
		$this->backend->expects($this->once())
			->method('getCalendarObject')
			->with(42, 'meeting.ics')
			->willReturn(self::backendRow('b7c1f0e2-meeting'));
		$this->logger->expects($this->never())->method($this->anything());

		$this->assertSame('b7c1f0e2-meeting', $this->reader->uidFor(42, self::restoredRow()));
	}

	public function testAsksForTheCalendarAndTheFileNameItWasGiven(): void {
		$this->backend->expects($this->once())
			->method('getCalendarObject')
			->with(77, 'other.ics')
			->willReturn(self::backendRow('uid-of-other'));

		$this->assertSame('uid-of-other', $this->reader->uidFor(77, self::restoredRow('other.ics')));
	}

	/** @return array<string, array{mixed, int}> the type in the row, the type the backend must get */
	public static function calendarTypes(): array {
		return [
			'calendar' => [0, 0],
			'subscription' => [1, 1],
			'a number as text' => ['1', 1],
			'null' => [null, 0],
			'not a number' => ['x', 0],
		];
	}

	#[DataProvider('calendarTypes')]
	public function testAsksForTheKindOfCalendarTheRowNames(mixed $typeInTheRow, int $expected): void {
		$row = array_merge(self::restoredRow(), ['calendartype' => $typeInTheRow]);
		$this->backend->expects($this->once())
			->method('getCalendarObject')
			->with(42, 'meeting.ics', $this->identicalTo($expected))
			->willReturn(self::backendRow('b7c1f0e2-meeting'));

		$this->assertSame('b7c1f0e2-meeting', $this->reader->uidFor(42, $row));
	}

	public function testARowWithoutTheKindAsksForANormalCalendar(): void {
		$row = self::restoredRow();
		unset($row['calendartype']);
		$this->backend->expects($this->once())
			->method('getCalendarObject')
			->with(42, 'meeting.ics', $this->identicalTo(0))
			->willReturn(self::backendRow('b7c1f0e2-meeting'));

		$this->assertSame('b7c1f0e2-meeting', $this->reader->uidFor(42, $row));
	}

	public function testGivesNullWhenTheBackendFindsNoEvent(): void {
		$this->backend->expects($this->once())->method('getCalendarObject')->willReturn(null);

		$this->assertNull($this->reader->uidFor(42, self::restoredRow()));
	}

	public function testAFailedReadGivesNullAndIsLogged(): void {
		$failure = new \RuntimeException('db down');
		$this->backend->method('getCalendarObject')->willThrowException($failure);

		$logged = [];
		$this->logger->method($this->anything())->willReturnCallback(
			function (string $message, array $context = []) use (&$logged): void {
				$logged[] = $context;
			}
		);

		$this->assertNull($this->reader->uidFor(42, self::restoredRow()));
		$this->assertCount(1, $logged, 'one log line for one failed read');
		$this->assertSame($failure, $logged[0]['exception'] ?? null, 'the log line holds the exception');
		$this->assertSame('sendentsynchroniser', $logged[0]['app'] ?? null);
	}

	public function testAnErrorThatIsNotAnExceptionIsAlsoCaught(): void {
		// A TypeError is a Throwable but not an Exception.
		$this->backend->method('getCalendarObject')->willThrowException(new \TypeError('bad type'));

		$this->assertNull($this->reader->uidFor(42, self::restoredRow()));
	}

	/** @return array<string, array{mixed}> */
	public static function uidsThatAreNotUsable(): array {
		return [
			'null' => [null],
			'empty' => [''],
			'integer' => [1001],
			'array' => [['b7c1f0e2-meeting']],
		];
	}

	#[DataProvider('uidsThatAreNotUsable')]
	public function testAUidThatIsNotUsableGivesNull(mixed $uid): void {
		$this->backend->method('getCalendarObject')->willReturn(self::backendRow($uid));

		$this->assertNull($this->reader->uidFor(42, self::restoredRow()));
	}

	public function testABackendRowWithoutTheUidKeyGivesNull(): void {
		$row = self::backendRow('x');
		unset($row['uid']);
		$this->backend->method('getCalendarObject')->willReturn($row);

		$this->assertNull($this->reader->uidFor(42, self::restoredRow()));
	}

	/** @return array<string, array{array<string, mixed>|null}> */
	public static function objectRowsWithoutAUsableFileName(): array {
		$noUri = self::restoredRow();
		unset($noUri['uri']);

		return [
			'no row' => [null],
			'no uri' => [$noUri],
			'empty uri' => [self::restoredRow('')],
			'uri is not a string' => [array_merge(self::restoredRow(), ['uri' => 1001])],
		];
	}

	#[DataProvider('objectRowsWithoutAUsableFileName')]
	public function testWithoutAUsableFileNameTheBackendIsNotAsked(?array $objectRow): void {
		$this->backend->expects($this->never())->method('getCalendarObject');

		$this->assertNull($this->reader->uidFor(42, $objectRow));
	}
}
