<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Command;

use OCA\SendentSynchroniser\Command\ChangeNotificationSetup;
use OCA\SendentSynchroniser\Db\SequenceMapper;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
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

	/** @var SequenceMapper&MockObject */
	private $sequence;

	/** @var ChangeLedgerService&MockObject */
	private $ledger;

	private CommandTester $tester;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->sequence = $this->createMock(SequenceMapper::class);
		$this->ledger = $this->createMock(ChangeLedgerService::class);

		$command = new ChangeNotificationSetup(
			$this->config,
			$this->userManager,
			$this->sequence,
			$this->ledger,
		);
		$this->tester = new CommandTester($command);
	}

	public function testSetsBotUserAndTransport(): void {
		$this->userManager->method('userExists')->with('sendent-sync')->willReturn(true);
		$this->config->expects($this->once())->method('setBotUser')->with('sendent-sync');
		$this->config->expects($this->once())->method('setTransportMode')->with('auto');

		$exit = $this->tester->execute(['--bot-user' => 'sendent-sync', '--transport' => 'auto']);

		$this->assertSame(0, $exit);
	}

	public function testRejectsAMissingBotUserWithoutCreate(): void {
		$this->userManager->method('userExists')->willReturn(false);
		$this->config->expects($this->never())->method('setBotUser');

		$exit = $this->tester->execute(['--bot-user' => 'ghost']);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('does not exist', $this->tester->getDisplay());
	}

	public function testCreateFlagCreatesAMissingBotUser(): void {
		$this->userManager->method('userExists')->with('sendent-sync')->willReturn(false);
		$this->userManager->expects($this->once())
			->method('createUser')
			->with('sendent-sync', $this->anything())
			->willReturn($this->createMock(\OCP\IUser::class));
		$this->config->expects($this->once())->method('setBotUser')->with('sendent-sync');

		$exit = $this->tester->execute(['--bot-user' => 'sendent-sync', '--create' => true]);

		$this->assertSame(0, $exit);
	}

	public function testRejectsAnUnknownTransport(): void {
		$this->config->method('setTransportMode')
			->willThrowException(new \InvalidArgumentException('Unknown transport mode: wat'));

		$exit = $this->tester->execute(['--transport' => 'wat']);

		$this->assertSame(1, $exit);
	}

	public function testReseedLiftsTheSequenceAboveTheLedger(): void {
		$this->ledger->method('highWaterMark')->willReturn(5000);
		$this->config->method('flushedSeq')->willReturn(0);
		$this->sequence->expects($this->once())->method('reseedAbove')->with(5000);

		$exit = $this->tester->execute(['--reseed' => true]);

		$this->assertSame(0, $exit);
	}

	public function testReseedFloorsAtTheFlushedWatermark(): void {
		$this->ledger->method('highWaterMark')->willReturn(100);
		$this->config->method('flushedSeq')->willReturn(5000);
		$this->sequence->expects($this->once())->method('reseedAbove')->with(5000);

		$exit = $this->tester->execute(['--reseed' => true]);

		$this->assertSame(0, $exit);
	}
}
