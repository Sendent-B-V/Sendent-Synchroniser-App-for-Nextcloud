<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\SignalMetrics;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SignalMetricsTest extends TestCase {

	/** @var IAppConfig&MockObject */
	private $appConfig;

	/** @var ITimeFactory&MockObject */
	private $time;

	/** @var array<string, string> */
	private array $values = [];

	private SignalMetrics $metrics;

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
		$this->time = $this->createMock(ITimeFactory::class);
		$this->metrics = new SignalMetrics($this->appConfig, $this->time);
	}

	public function testFlushesAccumulateWithinTheHourBucket(): void {
		$this->time->method('getTime')->willReturn(3600 * 100 + 60);

		$this->metrics->recordFlush(18, false);
		$this->metrics->recordFlush(500, true);

		$snapshot = $this->metrics->lastHour();
		$this->assertSame(2, $snapshot['flushes']);
		$this->assertSame(518, $snapshot['refs']);
		$this->assertSame(1, $snapshot['truncated']);
		$this->assertSame(500, $snapshot['max_refs']);
	}

	public function testANewHourStartsAFreshBucket(): void {
		$times = [3600 * 100, 3600 * 101];
		$i = 0;
		$this->time->method('getTime')->willReturnCallback(function () use (&$i, $times) {
			return $times[min($i++, 1)];
		});

		$this->metrics->recordFlush(10, false);   // hour 100
		$this->metrics->recordFlush(20, false);   // hour 101 — new bucket

		$snapshot = $this->metrics->lastHour();
		$this->assertSame(1, $snapshot['flushes']);
		$this->assertSame(20, $snapshot['refs']);
	}

	public function testAnEmptyHistoryReadsAsZeroes(): void {
		$this->time->method('getTime')->willReturn(0);

		$snapshot = $this->metrics->lastHour();
		$this->assertSame(0, $snapshot['flushes']);
		$this->assertSame(0, $snapshot['refs']);
		$this->assertSame(0, $snapshot['truncated']);
	}
}
