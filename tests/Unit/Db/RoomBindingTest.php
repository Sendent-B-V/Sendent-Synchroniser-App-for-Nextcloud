<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Db;

use OCA\SendentSynchroniser\Db\RoomBinding;
use PHPUnit\Framework\TestCase;

class RoomBindingTest extends TestCase {
	public function testJsonSerializeOmitsConfig(): void {
		$b = new RoomBinding();
		$b->setRoomId('boardroom-a');
		$b->setKind('exchange');
		$b->setExternalId('boardroom-a@contoso.com');
		$b->setConfig('{}');
		$b->setLinkVersion(3);
		$b->setState('completed');
		$b->setInitialSyncRequested(false);
		$b->setLastEventsPushed(5);
		$b->setLastEventsPulled(2);

		$json = $b->jsonSerialize();
		$this->assertSame('boardroom-a', $json['roomId']);
		$this->assertSame('exchange', $json['kind']);
		$this->assertSame('boardroom-a@contoso.com', $json['externalId']);
		$this->assertSame(3, $json['linkVersion']);
		$this->assertSame('completed', $json['state']);
		$this->assertFalse($json['initialSyncRequested']);
		$this->assertSame(5, $json['stats']['eventsPushed']);
		$this->assertSame(2, $json['stats']['eventsPulled']);
		$this->assertArrayNotHasKey('config', $json);
	}

	public function testGetConfigArrayDecodesJson(): void {
		$b = new RoomBinding();
		$b->setConfig('{"foo":"bar"}');
		$this->assertSame(['foo' => 'bar'], $b->getConfigArray());
	}

	public function testGetConfigArrayReturnsEmptyForBlank(): void {
		$b = new RoomBinding();
		$b->setConfig('');
		$this->assertSame([], $b->getConfigArray());
	}
}
