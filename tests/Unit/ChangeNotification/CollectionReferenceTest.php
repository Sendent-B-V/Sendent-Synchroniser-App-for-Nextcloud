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
			false
		);

		$this->assertSame("principals/users/alice\x00caldav\x00personal", $ref->key());
	}

	public function testKeyIgnoresTheStructuralFlag(): void {
		$a = new CollectionReference('principals/users/alice', 'caldav', 'personal', false);
		$b = new CollectionReference('principals/users/alice', 'caldav', 'personal', true);

		$this->assertSame($a->key(), $b->key());
	}

	public function testKeyIsUnambiguousWhenAUriContainsThePipeCharacter(): void {
		$a = new CollectionReference('principals/users/x', 'caldav', 'y|caldav|z', false);
		$b = new CollectionReference('principals/users/x|caldav|y', 'caldav', 'z', false);

		$this->assertNotSame($a->key(), $b->key());
	}

	public function testJsonSerializeUsesTheShortWireFieldNames(): void {
		// No sync token: the Connector always syncs with the token it stored.
		$ref = new CollectionReference('principals/users/bob', 'carddav', 'contacts', true);

		$this->assertSame(
			['p' => 'principals/users/bob', 't' => 'carddav', 'u' => 'contacts', 'c' => true],
			$ref->jsonSerialize()
		);
	}
}
