<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChangeNotificationConfigTest extends TestCase {

	/** @var IAppConfig&MockObject */
	private $appConfig;

	private ChangeNotificationConfig $config;

	/** @var array<string, string> */
	private array $values = [];

	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getAppValue')->willReturnCallback(
			fn (string $key, $default = '') => $this->values[$key] ?? $default
		);
		$this->appConfig->method('setAppValue')->willReturnCallback(
			function (string $key, string $value): void {
				$this->values[$key] = $value;
			}
		);
		$this->config = new ChangeNotificationConfig($this->appConfig);
	}

	public function testDefaults(): void {
		$this->assertSame('auto', $this->config->transportMode());
		$this->assertSame('', $this->config->botUser());
		$this->assertSame(2, $this->config->batchWindow());
		$this->assertSame(500, $this->config->maxRefsPerSignal());
		$this->assertSame(30, $this->config->pollInterval());
		$this->assertSame(100, $this->config->rereadOverlap());
		$this->assertSame(0, $this->config->flushedSeq());
	}

	public function testBatchWindowIsClamped(): void {
		$this->values['cnBatchWindow'] = '-4';
		$this->assertSame(0, $this->config->batchWindow());

		$this->values['cnBatchWindow'] = '99';
		$this->assertSame(10, $this->config->batchWindow());
	}

	public function testPollIntervalIsClamped(): void {
		$this->values['cnPollInterval'] = '1';
		$this->assertSame(5, $this->config->pollInterval());

		$this->values['cnPollInterval'] = '9999';
		$this->assertSame(300, $this->config->pollInterval());
	}

	public function testMaxRefsIsClamped(): void {
		$this->values['cnMaxRefsPerSignal'] = '0';
		$this->assertSame(1, $this->config->maxRefsPerSignal());

		$this->values['cnMaxRefsPerSignal'] = '100000';
		$this->assertSame(5000, $this->config->maxRefsPerSignal());
	}

	public function testTransportModeFallsBackToAutoOnGarbage(): void {
		$this->values['cnTransportMode'] = 'wat';
		$this->assertSame('auto', $this->config->transportMode());

		$this->values['cnTransportMode'] = 'polling';
		$this->assertSame('polling', $this->config->transportMode());
	}

	public function testFlushedSeqRoundTrips(): void {
		$this->config->setFlushedSeq(1234);
		$this->assertSame(1234, $this->config->flushedSeq());
	}

	public function testFlushedSeqNeverGoesBackwards(): void {
		$this->config->setFlushedSeq(1234);
		$this->config->setFlushedSeq(12);
		$this->assertSame(1234, $this->config->flushedSeq());
	}
}
