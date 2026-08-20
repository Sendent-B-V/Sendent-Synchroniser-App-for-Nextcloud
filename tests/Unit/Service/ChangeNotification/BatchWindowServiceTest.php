<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\ChangeNotification;

use OCA\SendentSynchroniser\Service\ChangeNotification\BatchWindowService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IMemcache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BatchWindowServiceTest extends TestCase {

	/** @var ICacheFactory&MockObject */
	private $cacheFactory;

	/** @var IMemcache&MockObject */
	private $memcache;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var IConfig&MockObject */
	private $serverConfig;

	protected function setUp(): void {
		parent::setUp();
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$this->memcache = $this->createMock(IMemcache::class);
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->config->method('batchWindow')->willReturn(2);
		$this->serverConfig = $this->createMock(IConfig::class);
	}

	private function service(bool $distributedConfigured = true, bool $cacheAvailable = true): BatchWindowService {
		$this->serverConfig->method('getSystemValue')->with('memcache.distributed', null)
			->willReturn($distributedConfigured ? '\\OC\\Memcache\\Redis' : null);
		$this->cacheFactory->method('isAvailable')->willReturn($cacheAvailable);
		$this->cacheFactory->method('createDistributed')->willReturn($this->memcache);

		return new BatchWindowService($this->cacheFactory, $this->serverConfig, $this->config);
	}

	public function testFirstCallerInAWindowWinsTheFlush(): void {
		$this->memcache->expects($this->once())
			->method('add')
			->with($this->anything(), 1, 2)
			->willReturn(true);

		$this->assertTrue($this->service()->tryOpenWindow());
	}

	public function testLaterCallersInTheSameWindowLose(): void {
		$this->memcache->method('add')->willReturn(false);

		$this->assertFalse($this->service()->tryOpenWindow());
	}

	public function testAZeroWindowAlwaysFlushes(): void {
		// window 0 = batching off: every event may flush; add() is never asked.
		$config = $this->createMock(ChangeNotificationConfig::class);
		$config->method('batchWindow')->willReturn(0);
		$this->serverConfig->method('getSystemValue')->willReturn('\\OC\\Memcache\\Redis');
		$this->cacheFactory->method('isAvailable')->willReturn(true);
		$this->cacheFactory->method('createDistributed')->willReturn($this->memcache);
		$this->memcache->expects($this->never())->method('add');

		$service = new BatchWindowService($this->cacheFactory, $this->serverConfig, $config);

		$this->assertTrue($service->tryOpenWindow());
	}

	public function testWithoutAConfiguredDistributedCacheTheWindowNeverOpens(): void {
		// No distributed cache = no notify_push either; polling reads the
		// ledger directly, so an in-request flush would only burn CPU. Also
		// covers APCu-only installs, where createDistributed() falls back to
		// a per-process local cache whose add() would elect one "winner" per
		// php-fpm worker instead of one per window.
		$this->assertFalse($this->service(false)->tryOpenWindow());
	}
}
