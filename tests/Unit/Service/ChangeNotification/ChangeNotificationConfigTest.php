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
		$this->assertSame(30, $this->config->pollInterval());
	}

	public function testTheRetiredForceNotifyPushModeReadsAsAuto(): void {
		// notify_push is offered whenever it is configured; there is nothing
		// left to force.
		$this->values['cnTransportMode'] = 'notify_push';

		$this->assertSame('auto', $this->config->transportMode());
	}

	public function testTheRetiredForceNotifyPushModeCannotBeSet(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->config->setTransportMode('notify_push');
	}

	public function testPollIntervalIsClamped(): void {
		$this->values['cnPollInterval'] = '1';
		$this->assertSame(5, $this->config->pollInterval());

		$this->values['cnPollInterval'] = '9999';
		$this->assertSame(300, $this->config->pollInterval());
	}

	public function testSetPollIntervalClampsOnWrite(): void {
		$this->config->setPollInterval(1);
		$this->assertSame('5', $this->values['cnPollInterval']);
	}

	public function testTransportModeFallsBackToAutoOnGarbage(): void {
		$this->values['cnTransportMode'] = 'wat';
		$this->assertSame('auto', $this->config->transportMode());

		$this->values['cnTransportMode'] = 'polling';
		$this->assertSame('polling', $this->config->transportMode());
	}

	public function testTheSequenceFloorOnlyRises(): void {
		$this->config->raiseSeqFloor(1234);
		$this->config->raiseSeqFloor(12);
		$this->assertSame(1234, $this->config->seqFloor());
	}

	public function testTheSequenceOffsetOnlyRises(): void {
		// Two racing reseeds store their own lifts; the smaller one landing
		// last must not undo the larger.
		$this->config->raiseSeqOffset(5000);
		$this->config->raiseSeqOffset(4990);
		$this->assertSame(5000, $this->config->seqOffset());
	}
}
