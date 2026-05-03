<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Db;

use OCA\SendentSynchroniser\Db\RoomGroup;
use PHPUnit\Framework\TestCase;

class RoomGroupTest extends TestCase {
	public function testJsonSerialize(): void {
		$g = new RoomGroup();
		$g->setId('exec');
		$g->setName('Executive Floor');
		$g->setDescription('Top-floor rooms');

		$json = $g->jsonSerialize();
		$this->assertSame('exec', $json['id']);
		$this->assertSame('Executive Floor', $json['name']);
		$this->assertSame('Top-floor rooms', $json['description']);
	}
}
