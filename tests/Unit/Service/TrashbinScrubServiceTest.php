<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Service\TrashbinScrubService;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TrashbinScrubServiceTest extends TestCase {

	/** @var IAppConfig&MockObject */
	private $appConfig;

	private TrashbinScrubService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->service = new TrashbinScrubService($this->appConfig);
	}

	private function setToggle(string $value): void {
		$this->appConfig->method('getAppValue')->willReturnCallback(
			function (string $key, $default = '') use ($value) {
				if ($key === Constants::TRASHBIN_SCRUB_KEY) {
					return $value;
				}
				return $default;
			}
		);
	}

	/**
	 * Builds a VCALENDAR from raw component blocks (already CRLF-terminated lines).
	 */
	private function ics(string ...$components): string {
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n"
			. implode('', $components)
			. "END:VCALENDAR\r\n";
	}

	public function testIsEnabledTrue(): void {
		$this->setToggle('true');
		$this->assertTrue($this->service->isEnabled());
	}

	public function testIsEnabledFalse(): void {
		$this->setToggle('false');
		$this->assertFalse($this->service->isEnabled());
	}

	public function testIsEnabledDefaultsToDisabled(): void {
		$this->appConfig->method('getAppValue')->willReturnCallback(
			fn (string $key, $default = '') => $default
		);
		$this->assertFalse($this->service->isEnabled());
	}

	public function testReturnsNullWhenNoSendentPropertiesPresent(): void {
		$data = $this->ics(
			"BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\nSUMMARY:Plain\r\nEND:VEVENT\r\n"
		);

		$this->assertNull($this->service->stripSendentProperties($data));
	}

	public function testStripsMultipleSendentPropertiesFromSingleEvent(): void {
		$data = $this->ics(
			"BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\n"
			. "SUMMARY:Meeting\r\n"
			. "X-SENDENT-ID:abc-123\r\n"
			. "X-SENDENT-CHANGEKEY:xyz\r\n"
			. "X-SENDENT-ORIGIN:exchange\r\n"
			. "END:VEVENT\r\n"
		);

		$result = $this->service->stripSendentProperties($data);

		$this->assertNotNull($result);
		$this->assertStringNotContainsStringIgnoringCase('X-SENDENT', $result);
		$this->assertStringContainsString('SUMMARY:Meeting', $result);
		$this->assertStringContainsString('UID:1', $result);
	}

	public function testStripsFromRecurrenceOverrideEvents(): void {
		$data = $this->ics(
			"BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\n"
			. "RRULE:FREQ=DAILY\r\nSUMMARY:Series\r\nX-SENDENT-ID:base\r\nEND:VEVENT\r\n",
			"BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20260101T000000Z\r\nRECURRENCE-ID:20260102T100000Z\r\n"
			. "DTSTART:20260102T110000Z\r\nSUMMARY:Moved instance\r\nX-SENDENT-ID:override\r\nEND:VEVENT\r\n"
		);

		$result = $this->service->stripSendentProperties($data);

		$this->assertNotNull($result);
		$this->assertStringNotContainsStringIgnoringCase('X-SENDENT', $result);
		// Both events survive, including the RECURRENCE-ID override.
		$this->assertStringContainsString('RECURRENCE-ID', $result);
		$this->assertStringContainsString('SUMMARY:Moved instance', $result);
	}

	public function testStripsFromNestedSubComponents(): void {
		$data = $this->ics(
			"BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\n"
			. "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT15M\r\nX-SENDENT-ALARM:1\r\nEND:VALARM\r\n"
			. "END:VEVENT\r\n"
		);

		$result = $this->service->stripSendentProperties($data);

		$this->assertNotNull($result);
		$this->assertStringNotContainsStringIgnoringCase('X-SENDENT', $result);
		$this->assertStringContainsString('BEGIN:VALARM', $result);
		$this->assertStringContainsString('TRIGGER:-PT15M', $result);
	}

	public function testStripsCalendarLevelPropertyAndKeepsTimezone(): void {
		$data = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n"
			. "X-SENDENT-SYNC:enabled\r\n"
			. "BEGIN:VTIMEZONE\r\nTZID:Europe/Amsterdam\r\n"
			. "BEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nEND:STANDARD\r\n"
			. "END:VTIMEZONE\r\n"
			. "BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20260101T000000Z\r\n"
			. "DTSTART;TZID=Europe/Amsterdam:20260101T100000\r\nSUMMARY:Tz event\r\nEND:VEVENT\r\n"
			. "END:VCALENDAR\r\n";

		$result = $this->service->stripSendentProperties($data);

		$this->assertNotNull($result);
		$this->assertStringNotContainsStringIgnoringCase('X-SENDENT', $result);
		$this->assertStringContainsString('BEGIN:VTIMEZONE', $result);
		$this->assertStringContainsString('TZID:Europe/Amsterdam', $result);
	}

	public function testMatchesCaseInsensitively(): void {
		$data = $this->ics(
			"BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\n"
			. "x-sendent-id:lowercase\r\nEND:VEVENT\r\n"
		);

		$result = $this->service->stripSendentProperties($data);

		$this->assertNotNull($result);
		$this->assertStringNotContainsStringIgnoringCase('X-SENDENT', $result);
	}

	public function testStripsPropertyCarryingParameters(): void {
		$data = $this->ics(
			"BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\n"
			. "X-SENDENT-REF;X-PARAM=value:ref-1\r\nEND:VEVENT\r\n"
		);

		$result = $this->service->stripSendentProperties($data);

		$this->assertNotNull($result);
		$this->assertStringNotContainsStringIgnoringCase('X-SENDENT', $result);
	}

	public function testKeepsUnrelatedCustomProperties(): void {
		$data = $this->ics(
			"BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\n"
			. "X-MICROSOFT-CDO-BUSYSTATUS:BUSY\r\nX-SENDENT-ID:abc\r\nEND:VEVENT\r\n"
		);

		$result = $this->service->stripSendentProperties($data);

		$this->assertNotNull($result);
		$this->assertStringContainsString('X-MICROSOFT-CDO-BUSYSTATUS:BUSY', $result);
		$this->assertStringNotContainsStringIgnoringCase('X-SENDENT', $result);
	}

	public function testReturnsNullWhenPrefixOnlyAppearsInsideAValue(): void {
		// stripos() pre-check hits, but no property NAME matches — no rewrite.
		$data = $this->ics(
			"BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\n"
			. "DESCRIPTION:Mentions X-SENDENT in text only\r\nEND:VEVENT\r\n"
		);

		$this->assertNull($this->service->stripSendentProperties($data));
	}

	public function testStrippedOutputIsStillParseableCalendar(): void {
		$data = $this->ics(
			"BEGIN:VEVENT\r\nUID:1\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\n"
			. "SUMMARY:Meeting\r\nX-SENDENT-ID:abc\r\nEND:VEVENT\r\n"
		);

		$result = $this->service->stripSendentProperties($data);

		$this->assertNotNull($result);
		$reparsed = \Sabre\VObject\Reader::read($result);
		$this->assertSame('Meeting', (string)$reparsed->VEVENT->SUMMARY);
	}
}
