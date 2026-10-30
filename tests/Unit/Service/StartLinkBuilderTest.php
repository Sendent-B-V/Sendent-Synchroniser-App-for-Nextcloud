<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Service\Base64Url;
use OCA\SendentSynchroniser\Service\NonceSource;
use OCA\SendentSynchroniser\Service\StartLinkBuilder;
use OCA\SendentSynchroniser\Service\StartLinkUnavailableException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the test vector shared with the sync service byte for byte. The
 * service's start endpoint tests assert the same link from the other side;
 * together they are the proof that both implementations agree.
 */
class StartLinkBuilderTest extends TestCase {

	// Test vector shared with the sync service, verbatim.
	private const SECRET = 'test-shared-secret';
	private const USER = 'alice';
	private const TS = 1760400000;
	private const NONCE_BYTES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F";
	private const PUBLIC_URL = 'https://sync.example.com';
	private const EXPECTED_LINK = 'https://sync.example.com/auth/graph/start?user=alice&ts=1760400000&nonce=AAECAwQFBgcICQoLDA0ODw&sig=6eiti_K3cHGK5lYGmuipueWggPkIjySaiUk2HD8p7zs';

	private IAppConfig&Stub $appConfig;
	private ITimeFactory&MockObject $timeFactory;
	private NonceSource&MockObject $nonceSource;
	private StartLinkBuilder $builder;

	protected function setUp(): void {
		$this->appConfig = $this->createStub(IAppConfig::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->nonceSource = $this->createMock(NonceSource::class);
		$this->builder = new StartLinkBuilder($this->appConfig, $this->timeFactory, $this->nonceSource);
	}

	/**
	 * Makes getAppValue answer per key. Pass only the keys you want to
	 * override; the rest fall back to the test vector's happy-path values.
	 *
	 * @param array<string,string> $overrides
	 */
	private function configure(array $overrides = []): void {
		$values = array_merge([
			Constants::GRAPH_AUTH_METHOD_KEY => Constants::GRAPH_AUTH_METHOD_PER_USER_FALLBACK,
			Constants::SYNC_SERVICE_PUBLIC_URL_KEY => self::PUBLIC_URL,
			'sharedSecret' => self::SECRET,
		], $overrides);
		$this->appConfig->method('getAppValue')
			->willReturnCallback(fn (string $key, string $default = '') => $values[$key] ?? $default);
	}

	private function expectRefusal(string $reason, string $userId = self::USER): void {
		$this->timeFactory->expects($this->never())->method('getTime');
		$this->nonceSource->expects($this->never())->method('bytes');
		try {
			$this->builder->build($userId);
			$this->fail('expected StartLinkUnavailableException');
		} catch (StartLinkUnavailableException $e) {
			$this->assertSame($reason, $e->getReason());
		}
	}

	private function c6Clock(): void {
		$this->timeFactory->expects($this->once())->method('getTime')->willReturn(self::TS);
		$this->nonceSource->expects($this->once())->method('bytes')->with(16)->willReturn(self::NONCE_BYTES);
	}

	public function testBuildsTheC6TestVector(): void {
		$this->configure();
		$this->c6Clock();

		// Byte for byte: assertSame, not assertEquals, not a regex.
		$this->assertSame(self::EXPECTED_LINK, $this->builder->build(self::USER));
	}

	/** Guards against "//auth" in the path when the admin typed a trailing slash. */
	public function testTrailingSlashOnPublicUrlIsIgnored(): void {
		$this->configure([Constants::SYNC_SERVICE_PUBLIC_URL_KEY => self::PUBLIC_URL . '/']);
		$this->c6Clock();

		$this->assertSame(self::EXPECTED_LINK, $this->builder->build(self::USER));
	}

	/**
	 * `user` is URL-encoded in the link and raw in the string to sign. We pin
	 * the encoding to percent-encoding (rawurlencode), never '+' for a space.
	 */
	public function testUserIdIsPercentEncodedInLinkButRawInSignature(): void {
		$this->configure();
		$this->c6Clock();
		$userId = 'al ice@example';
		$expectedSig = Base64Url::encode(hash_hmac(
			'sha256',
			"graph\n" . $userId . "\n1760400000\nAAECAwQFBgcICQoLDA0ODw",
			self::SECRET,
			true,
		));

		$link = $this->builder->build($userId);

		$this->assertStringContainsString('?user=al%20ice%40example&', $link);
		$this->assertStringNotContainsString('al+ice', $link);
		$this->assertStringEndsWith('&sig=' . $expectedSig, $link);
	}

	public function testRefusesInServiceAccountMode(): void {
		$this->configure([Constants::GRAPH_AUTH_METHOD_KEY => Constants::GRAPH_AUTH_METHOD_SERVICE_ACCOUNT]);

		$this->expectRefusal(StartLinkUnavailableException::REASON_SERVICE_ACCOUNT);
	}

	/** normaliseGraphAuthMethod maps unknown values to the default, which is serviceAccount. */
	public function testUnknownStoredModeIsTreatedAsServiceAccount(): void {
		$this->configure([Constants::GRAPH_AUTH_METHOD_KEY => 'garbage']);

		$this->expectRefusal(StartLinkUnavailableException::REASON_SERVICE_ACCOUNT);
	}

	public function testRefusesWhenPublicUrlIsEmpty(): void {
		$this->configure([Constants::SYNC_SERVICE_PUBLIC_URL_KEY => '']);

		$this->expectRefusal(StartLinkUnavailableException::REASON_PUBLIC_URL);
	}

	public function testRefusesWhenSharedSecretIsEmpty(): void {
		$this->configure(['sharedSecret' => '']);

		$this->expectRefusal(StartLinkUnavailableException::REASON_SHARED_SECRET);
	}

	/** The nonce is asked for on every call, 16 bytes each time, never cached. */
	public function testEveryLinkGetsAFreshNonce(): void {
		$this->configure();
		$this->timeFactory->expects($this->exactly(2))->method('getTime')->willReturn(self::TS);
		$this->nonceSource->expects($this->exactly(2))->method('bytes')->with(16)
			->willReturnOnConsecutiveCalls(self::NONCE_BYTES, str_repeat("\xFF", 16));

		$first = $this->builder->build(self::USER);
		$second = $this->builder->build(self::USER);

		$this->assertNotSame($first, $second);
		$this->assertStringContainsString('&nonce=AAECAwQFBgcICQoLDA0ODw&', $first);
		$this->assertStringContainsString('&nonce=_____________________w&', $second);
	}
}
