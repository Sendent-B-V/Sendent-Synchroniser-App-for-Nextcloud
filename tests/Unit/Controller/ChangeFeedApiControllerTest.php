<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Controller\ChangeFeedApiController;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeFeedGuard;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChangeFeedApiControllerTest extends TestCase {

	/** @var ChangeFeedGuard&MockObject */
	private $guard;

	/** @var ChangeLedgerService&MockObject */
	private $ledger;

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var CursorService&MockObject */
	private $cursor;

	/** @var NotifyPushAvailability&MockObject */
	private $availability;

	private ChangeFeedApiController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->guard = $this->createMock(ChangeFeedGuard::class);
		$this->ledger = $this->createMock(ChangeLedgerService::class);
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->cursor = $this->createMock(CursorService::class);
		$this->availability = $this->createMock(NotifyPushAvailability::class);

		$serverConfig = $this->createMock(IConfig::class);
		$serverConfig->method('getSystemValueString')->with('instanceid')->willReturn('inst');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1755676800);

		$appManager = $this->createMock(\OCP\App\IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('2.1.0');

		$this->controller = new ChangeFeedApiController(
			'sendentsynchroniser',
			$this->createMock(IRequest::class),
			$this->guard,
			$this->ledger,
			$this->config,
			$this->cursor,
			$this->availability,
			$serverConfig,
			$time,
			$appManager,
		);
	}

	private function allow(): void {
		$this->guard->method('isAllowed')->willReturn(true);
	}

	public function testEveryEndpointRejectsNonBotNonAdminWith403(): void {
		$this->guard->method('isAllowed')->willReturn(false);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->config()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->changes(0, 10)->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->ack(1)->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->health()->getStatus());
	}

	public function testConfigDescribesTheTransportContract(): void {
		$this->allow();
		$this->availability->method('effectiveTransport')->willReturn('notify_push');
		$this->availability->method('websocketUrl')->willReturn('wss://cloud.example.com/push/ws');
		$this->config->method('pollInterval')->willReturn(30);
		$this->config->method('batchWindow')->willReturn(2);
		$this->config->method('rereadOverlap')->willReturn(100);
		$this->config->method('botUser')->willReturn('sendent-sync');
		$this->cursor->method('current')->willReturn(1849233);

		$data = $this->controller->config()->getData();

		$this->assertSame('notify_push', $data['transport']);
		$this->assertSame('wss://cloud.example.com/push/ws', $data['ws_url']);
		$this->assertSame(30, $data['poll_interval']);
		$this->assertSame(2, $data['batch_window']);
		$this->assertSame(100, $data['reread_overlap']);
		$this->assertSame(1849233, $data['cursor']);
		$this->assertSame('sendent-sync', $data['bot_user']);
		$this->assertSame('inst', $data['instance']);
		$this->assertSame('sendent_sync', $data['message_name']);
	}

	public function testChangesReturnsAPageWithCursorAndHasMore(): void {
		$this->allow();
		$row = new \OCA\SendentSynchroniser\Db\DirtyCollection();
		$row->setPrincipalUri('principals/users/alice');
		$row->setCollectionType('caldav');
		$row->setCollectionUri('personal');
		$row->setSyncToken(9651);
		$row->setChangeSeq(600);
		$row->setStructuralSeq(0);
		$row->setUpdatedAt(1);

		// limit 1 requested; two rows fetched (limit+1) signals has_more.
		$second = clone $row;
		$second->setChangeSeq(601);
		$this->ledger->method('rows')->with(500, 2)->willReturn([$row, $second]);

		$data = $this->controller->changes(500, 1)->getData();

		$this->assertSame(1, $data['v']);
		$this->assertSame('inst', $data['instance']);
		$this->assertCount(1, $data['refs']);
		$this->assertSame(600, $data['cursor']);
		$this->assertTrue($data['has_more']);
		$this->assertSame('personal', $data['refs'][0]['u']);
	}

	public function testChangesOnAnEmptyLedgerEchoesSince(): void {
		$this->allow();
		$this->ledger->method('rows')->willReturn([]);

		$data = $this->controller->changes(1849233, 100)->getData();

		$this->assertSame([], $data['refs']);
		$this->assertSame(1849233, $data['cursor']);
		$this->assertFalse($data['has_more']);
	}

	public function testChangesClampsTheLimit(): void {
		$this->allow();
		// limit above the hard cap fetches CN_CHANGES_LIMIT_MAX + 1
		$this->ledger->expects($this->once())->method('rows')->with(0, 1001)->willReturn([]);

		$this->controller->changes(0, 999999);
	}

	public function testChangesRejectsNegativeSince(): void {
		$this->allow();
		$this->ledger->expects($this->once())->method('rows')->with(0, 501)->willReturn([]);

		$this->controller->changes(-5, 500);
	}

	public function testAckStoresCursorAndTimestamp(): void {
		$this->allow();
		$this->config->expects($this->once())->method('setAck')->with(1849190, 1755676800);

		$response = $this->controller->ack(1849190);

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}

	public function testHealthReportsTransportAndLag(): void {
		$this->allow();
		$this->availability->method('effectiveTransport')->willReturn('polling');
		$this->availability->method('cachedDaemonCheck')->willReturn(['ok' => false, 'at' => 0, 'message' => 'x']);
		$this->config->method('lastSignalAt')->willReturn(1755676700);
		$this->config->method('ackCursor')->willReturn(1849190);
		$this->config->method('ackAt')->willReturn(1755676798);
		$this->cursor->method('current')->willReturn(1849233);
		$this->ledger->method('countCollections')->willReturn(1240118);

		$data = $this->controller->health()->getData();

		$this->assertSame('polling', $data['transport']);
		$this->assertFalse($data['notify_push_ok']);
		$this->assertSame(1755676700, $data['last_signal_at']);
		$this->assertSame(1240118, $data['ledger_rows']);
		$this->assertSame(43, $data['connector_lag']);
	}
}
