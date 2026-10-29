<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service;

use OCA\SendentSynchroniser\Service\Base64Url;
use PHPUnit\Framework\TestCase;

class Base64UrlTest extends TestCase {

	/** Shared test vector nonce: bytes 0x00..0x0F. */
	private const NONCE_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F";
	private const NONCE_TEXT = 'AAECAwQFBgcICQoLDA0ODw';

	/** Shared test vector signature, as hex and as base64url. */
	private const SIG_HEX = 'e9e8ad8bf2b770718ae656069ae8a9b9e5a080f9088f249a8949361c3f29ef3b';
	private const SIG_TEXT = '6eiti_K3cHGK5lYGmuipueWggPkIjySaiUk2HD8p7zs';

	public function testEncodesC6NonceWithoutPadding(): void {
		$encoded = Base64Url::encode(self::NONCE_BYTES);

		$this->assertSame(self::NONCE_TEXT, $encoded);
		$this->assertSame(22, strlen($encoded));
	}

	/** This vector contains an underscore, so it proves the '/' => '_' swap. */
	public function testEncodesC6SignatureWithUrlSafeAlphabet(): void {
		$encoded = Base64Url::encode(hex2bin(self::SIG_HEX));

		$this->assertSame(self::SIG_TEXT, $encoded);
		$this->assertSame(43, strlen($encoded));
	}

	public function testDecodeIsTheInverseOfEncode(): void {
		// 22 % 4 == 2 and 43 % 4 == 3, so both padding amounts are exercised.
		$this->assertSame(self::NONCE_BYTES, Base64Url::decode(self::NONCE_TEXT));
		$this->assertSame(hex2bin(self::SIG_HEX), Base64Url::decode(self::SIG_TEXT));
	}

	public function testDecodeRoundTripsLengthsThatNeedNoPadding(): void {
		$threeBytes = "\xDE\xAD\xBE";

		$encoded = Base64Url::encode($threeBytes);

		$this->assertSame(4, strlen($encoded));
		$this->assertSame($threeBytes, Base64Url::decode($encoded));
	}

	public function testDecodeRejectsInvalidInput(): void {
		$this->assertNull(Base64Url::decode('abc$'));
		$this->assertNull(Base64Url::decode('ab cd'));
	}
}
