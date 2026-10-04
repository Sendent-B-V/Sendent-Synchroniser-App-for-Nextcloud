<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Command;

use OCA\SendentSynchroniser\Command\ChangeNotificationSetup;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ChangeNotificationSetupTest extends TestCase {

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var IUserManager&MockObject */
	private $userManager;

	private CommandTester $tester;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->tester = new CommandTester(new ChangeNotificationSetup($this->config, $this->userManager));
	}

	public function testSetsBotUserAndTransport(): void {
		$this->userManager->method('userExists')->with('sendent-sync')->willReturn(true);
		$this->config->expects($this->once())->method('setBotUser')->with('sendent-sync');
		$this->config->expects($this->once())->method('setTransportMode')->with('auto');

		$exit = $this->tester->execute(['--bot-user' => 'sendent-sync', '--transport' => 'auto']);

		$this->assertSame(0, $exit);
	}

	public function testAMissingBotUserIsRejectedWithTheCommandsToCreateIt(): void {
		$this->userManager->method('userExists')->willReturn(false);
		$this->config->expects($this->never())->method('setBotUser');

		$exit = $this->tester->execute(['--bot-user' => 'ghost']);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('occ user:add ghost', $this->tester->getDisplay());
		$this->assertStringContainsString('occ user:auth-tokens:add ghost', $this->tester->getDisplay());
	}

	public function testRejectsAnUnknownTransport(): void {
		$this->config->method('setTransportMode')
			->willThrowException(new \InvalidArgumentException('Unknown transport mode: wat'));

		$exit = $this->tester->execute(['--transport' => 'wat']);

		$this->assertSame(1, $exit);
	}
}
