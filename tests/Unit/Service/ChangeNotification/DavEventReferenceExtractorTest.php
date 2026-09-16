<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\DavEventReferenceExtractor;
use PHPUnit\Framework\TestCase;

class DavEventReferenceExtractorTest extends TestCase {

	private DavEventReferenceExtractor $extractor;

	protected function setUp(): void {
		parent::setUp();
		$this->extractor = new DavEventReferenceExtractor();
	}

	public function testCalendarRowBecomesACaldavReference(): void {
		$ref = $this->extractor->fromCalendarRow([
			'id' => 42,
			'uri' => 'personal',
			'principaluri' => 'principals/users/alice',
			'synctoken' => 9651,
		], false);

		$this->assertNotNull($ref);
		$this->assertSame('principals/users/alice', $ref->principalUri);
		$this->assertSame('caldav', $ref->collectionType);
		$this->assertSame('personal', $ref->collectionUri);
		$this->assertSame(9651, $ref->syncToken);
		$this->assertFalse($ref->collectionChanged);
	}

	public function testAddressBookRowBecomesACarddavReference(): void {
		$ref = $this->extractor->fromAddressBookRow([
			'id' => 7,
			'uri' => 'contacts',
			'principaluri' => 'principals/users/bob',
			'synctoken' => 312,
		], true);

		$this->assertNotNull($ref);
		$this->assertSame('carddav', $ref->collectionType);
		$this->assertSame('contacts', $ref->collectionUri);
		$this->assertSame(312, $ref->syncToken);
		$this->assertTrue($ref->collectionChanged);
	}

	public function testSyncTokenIsReadFromTheSabreDavNamespacedKey(): void {
		$ref = $this->extractor->fromCalendarRow([
			'uri' => 'personal',
			'principaluri' => 'principals/users/alice',
			'{http://sabredav.org/ns}sync-token' => '77',
		], false);

		$this->assertSame(77, $ref->syncToken);
	}

	// Defensive coverage: shape not produced by current OCA\DAV backends.
	public function testSyncTokenStripsTheSabreSyncUrlPrefix(): void {
		$ref = $this->extractor->fromCalendarRow([
			'uri' => 'personal',
			'principaluri' => 'principals/users/alice',
			'{http://sabredav.org/ns}sync-token' => 'http://sabre.io/ns/sync/1234',
		], false);

		$this->assertSame(1234, $ref->syncToken);
	}

	// Defensive coverage: shape not produced by current OCA\DAV backends.
	public function testRawColumnWinsOverTheNamespacedKey(): void {
		$ref = $this->extractor->fromCalendarRow([
			'uri' => 'personal',
			'principaluri' => 'principals/users/alice',
			'synctoken' => 5,
			'{http://sabredav.org/ns}sync-token' => '9',
		], false);

		$this->assertSame(5, $ref->syncToken);
	}

	public function testMissingSyncTokenBecomesZero(): void {
		$ref = $this->extractor->fromCalendarRow([
			'uri' => 'personal',
			'principaluri' => 'principals/users/alice',
		], false);

		$this->assertSame(0, $ref->syncToken);
	}

	public function testNullRowYieldsNull(): void {
		$this->assertNull($this->extractor->fromCalendarRow(null, false));
	}

	public function testRowWithoutAPrincipalYieldsNull(): void {
		$this->assertNull($this->extractor->fromCalendarRow(['uri' => 'personal'], false));
	}

	public function testRowWithoutAUriYieldsNull(): void {
		$this->assertNull($this->extractor->fromCalendarRow(['principaluri' => 'principals/users/alice'], false));
	}

	public function testRowWithEmptyStringsYieldsNull(): void {
		$this->assertNull($this->extractor->fromCalendarRow([
			'uri' => '',
			'principaluri' => 'principals/users/alice',
		], false));
	}

	public function testSystemPrincipalsAreIgnored(): void {
		// Calendars owned by principals/system/* (birthday reminders, the
		// public calendar root) have no mailbox behind them.
		$this->assertNull($this->extractor->fromCalendarRow([
			'uri' => 'contact_birthdays',
			'principaluri' => 'principals/system/system',
		], false));
	}

	public function testGroupPrincipalsAreIgnored(): void {
		$this->assertNull($this->extractor->fromCalendarRow([
			'uri' => 'shared',
			'principaluri' => 'principals/groups/finance',
		], false));
	}
}
