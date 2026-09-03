<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Constants;
use PHPUnit\Framework\TestCase;

class CollectionReferenceTest extends TestCase {

	public function testKeyIdentifiesTheCollection(): void {
		$ref = new CollectionReference(
			'principals/users/alice',
			Constants::COLLECTION_TYPE_CALDAV,
			'personal',
			9651,
			false
		);

		$this->assertSame("principals/users/alice\x00caldav\x00personal", $ref->key());
	}

	public function testKeyIgnoresSyncTokenAndFlag(): void {
		$a = new CollectionReference('principals/users/alice', 'caldav', 'personal', 1, false);
		$b = new CollectionReference('principals/users/alice', 'caldav', 'personal', 2, true);

		$this->assertSame($a->key(), $b->key());
	}

	public function testKeyIsUnambiguousWhenAUriContainsThePipeCharacter(): void {
		$a = new CollectionReference('principals/users/x', 'caldav', 'y|caldav|z', 1, false);
		$b = new CollectionReference('principals/users/x|caldav|y', 'caldav', 'z', 1, false);

		$this->assertNotSame($a->key(), $b->key());
	}

	public function testJsonSerializeUsesTheShortWireFieldNames(): void {
		$ref = new CollectionReference('principals/users/bob', 'carddav', 'contacts', 312, true);

		$this->assertSame(
			['p' => 'principals/users/bob', 't' => 'carddav', 'u' => 'contacts', 's' => 312, 'c' => true],
			$ref->jsonSerialize()
		);
	}

	public function testWithCollectionChangedReturnsANewInstance(): void {
		$ref = new CollectionReference('principals/users/bob', 'carddav', 'contacts', 312, false);
		$flagged = $ref->withCollectionChanged(true);

		$this->assertFalse($ref->collectionChanged);
		$this->assertTrue($flagged->collectionChanged);
		$this->assertSame($ref->key(), $flagged->key());
	}
}
