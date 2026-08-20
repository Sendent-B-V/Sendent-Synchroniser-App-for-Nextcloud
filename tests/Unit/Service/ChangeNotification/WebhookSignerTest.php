<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\WebhookSigner;
use PHPUnit\Framework\TestCase;

class WebhookSignerTest extends TestCase {

	private WebhookSigner $signer;

	protected function setUp(): void {
		parent::setUp();
		$this->signer = new WebhookSigner();
	}

	public function testSignatureIsHmacSha256OverTimestampNonceAndBody(): void {
		$body = '{"v":1,"cursor":5,"refs":[]}';

		$signature = $this->signer->sign('secret', 1755676800, 'abc123', $body);

		$expected = hash_hmac('sha256', "1755676800.abc123.$body", 'secret');
		$this->assertSame($expected, $signature);
	}

	public function testVerifyAcceptsAValidSignature(): void {
		$body = '{"v":1}';
		$signature = $this->signer->sign('secret', 100, 'n', $body);

		$this->assertTrue($this->signer->verify('secret', 100, 'n', $body, $signature));
	}

	public function testVerifyRejectsATamperedBody(): void {
		$signature = $this->signer->sign('secret', 100, 'n', '{"v":1}');

		$this->assertFalse($this->signer->verify('secret', 100, 'n', '{"v":2}', $signature));
	}

	public function testHeaderValueCarriesAllParts(): void {
		$this->assertSame(
			't=100,n=abc,s=deadbeef',
			$this->signer->headerValue(100, 'abc', 'deadbeef')
		);
	}
}
