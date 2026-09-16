<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Service\ChangeNotification\SignalBuilder;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SignalBuilderTest extends TestCase {

	/** @var IConfig&MockObject */
	private $config;

	private SignalBuilder $builder;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValueString')->with('instanceid')->willReturn('f1a2instance');
		$this->builder = new SignalBuilder($this->config);
	}

	public function testSignalCarriesVersionInstancePrevCursorAndRefs(): void {
		$refs = [
			new CollectionReference('principals/users/alice', 'caldav', 'personal', 9651, false),
			new CollectionReference('principals/users/bob', 'caldav', 'team-x', 77, true),
		];

		$signal = $this->builder->build(1849200, 1849233, $refs, false);

		$this->assertSame(1, $signal['v']);
		$this->assertSame('f1a2instance', $signal['instance']);
		$this->assertSame(1849200, $signal['prev']);
		$this->assertSame(1849233, $signal['cursor']);
		$this->assertFalse($signal['truncated']);
		$this->assertSame(
			['p' => 'principals/users/alice', 't' => 'caldav', 'u' => 'personal', 's' => 9651, 'c' => false],
			$signal['refs'][0]
		);
		$this->assertTrue($signal['refs'][1]['c']);
	}

	public function testTruncatedSignalOmitsRefs(): void {
		$refs = [new CollectionReference('principals/users/alice', 'caldav', 'personal', 1, false)];

		$signal = $this->builder->build(0, 500, $refs, true);

		$this->assertTrue($signal['truncated']);
		$this->assertSame([], $signal['refs']);
	}

	public function testSignalIsJsonSerializable(): void {
		$signal = $this->builder->build(0, 1, [
			new CollectionReference('principals/users/alice', 'caldav', 'personal', 1, false),
		], false);

		$json = json_encode($signal);

		$this->assertIsString($json);
		$this->assertStringContainsString('"p":"principals\/users\/alice"', $json);
	}
}
