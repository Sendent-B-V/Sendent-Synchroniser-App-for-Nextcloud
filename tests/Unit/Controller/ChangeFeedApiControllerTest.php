<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Controller;

use OCA\SendentSynchroniser\Controller\ChangeFeedApiController;
use OCA\SendentSynchroniser\Db\DirtyCollection;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeFeedGuard;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeLedgerService;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\CursorService;
use OCA\SendentSynchroniser\Service\ChangeNotification\NotifyPushAvailability;
use OCP\AppFramework\Http;
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

		$this->controller = new ChangeFeedApiController(
			'sendentsynchroniser',
			$this->createMock(IRequest::class),
			$this->guard,
			$this->ledger,
			$this->config,
			$this->cursor,
			$this->availability,
			$serverConfig,
		);
	}

	private function allow(): void {
		$this->guard->method('isAllowed')->willReturn(true);
	}

	private function row(string $uri, int $seq): DirtyCollection {
		$row = new DirtyCollection();
		$row->setPrincipalUri('principals/users/alice');
		$row->setCollectionType('caldav');
		$row->setCollectionUri($uri);
		$row->setChangeSeq($seq);
		$row->setStructuralSeq(0);
		$row->setUpdatedAt(1);
		return $row;
	}

	public function testEveryEndpointRejectsNonBotNonAdminWith403(): void {
		$this->guard->method('isAllowed')->willReturn(false);

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->config()->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->changes(0, 10)->getStatus());
	}

	public function testConfigDescribesTheTransportContract(): void {
		$this->allow();
		$this->availability->method('effectiveTransport')->willReturn('notify_push');
		$this->availability->method('websocketUrl')->willReturn('wss://cloud.example.com/push/ws');
		$this->config->method('pollInterval')->willReturn(30);

		$data = $this->controller->config()->getData();

		// Only what the Connector acts on: the instance keys its stored cursor.
		$this->assertSame([
			'transport' => 'notify_push',
			'ws_url' => 'wss://cloud.example.com/push/ws',
			'message_name' => 'sendent_sync',
			'poll_interval' => 30,
			'reread_overlap' => 100,
			'instance' => 'inst',
		], $data);
	}

	public function testChangesReturnsAPageWithCursorAndHasMore(): void {
		$this->allow();
		$this->cursor->method('current')->willReturn(1000);
		// limit 1 requested; two rows fetched (limit+1) signals has_more.
		$this->ledger->method('rows')->with(500, 2)->willReturn([$this->row('personal', 600), $this->row('work', 601)]);

		$data = $this->controller->changes(500, 1)->getData();

		$this->assertSame(1, $data['v']);
		$this->assertSame('inst', $data['instance']);
		$this->assertCount(1, $data['refs']);
		$this->assertSame('personal', $data['refs'][0]['u']);
		$this->assertSame(600, $data['cursor']);
		$this->assertTrue($data['has_more']);
	}

	public function testEachRefCarriesItsSequenceSoOverlapReReadsCanBeSkipped(): void {
		// Every check re-reads an overlap. Without the row's sequence the
		// Connector cannot tell "read again" from "changed again".
		$this->allow();
		$this->cursor->method('current')->willReturn(1000);
		$this->ledger->method('rows')->willReturn([$this->row('personal', 600)]);

		$data = $this->controller->changes(500, 10)->getData();

		$this->assertSame(
			['p' => 'principals/users/alice', 't' => 'caldav', 'u' => 'personal', 'c' => false, 'q' => 600],
			$data['refs'][0]
		);
	}

	public function testTheFenceIsRaisedToTheCounterBeforeTheLedgerIsRead(): void {
		// A writer still committing a number at or below the fence sees it
		// after its commit and re-stamps its row above everything read here.
		$this->allow();
		$order = [];
		$this->cursor->method('current')->willReturnCallback(function () use (&$order): int {
			$order[] = 'current';
			return 700;
		});
		$this->cursor->expects($this->once())->method('raiseFence')->with(700)
			->willReturnCallback(function () use (&$order): void {
				$order[] = 'fence';
			});
		$this->ledger->method('rows')->willReturnCallback(function () use (&$order): array {
			$order[] = 'read';
			return [];
		});

		$this->controller->changes(500, 10);

		$this->assertSame(['current', 'fence', 'read'], $order);
	}

	public function testTheCursorNeverPassesTheFence(): void {
		// 702 was handed out after the fence was raised and committed before
		// the read. 701, handed out just before it, may still be uncommitted —
		// and its writer will not re-stamp, because 701 is above the fence.
		// Returning 702 would put 701 below the Connector's cursor.
		$this->allow();
		$this->cursor->method('current')->willReturn(700);
		$this->ledger->method('rows')->willReturn([$this->row('personal', 650), $this->row('work', 702)]);

		$data = $this->controller->changes(500, 10)->getData();

		$this->assertCount(2, $data['refs']);
		$this->assertSame(700, $data['cursor']);
	}

	public function testChangesOnAnEmptyLedgerEchoesSince(): void {
		$this->allow();
		$this->cursor->method('current')->willReturn(1849300);
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
}
