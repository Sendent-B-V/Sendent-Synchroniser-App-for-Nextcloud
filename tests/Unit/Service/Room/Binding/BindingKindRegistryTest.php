<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\Room\Binding;

use OCA\SendentSynchroniser\Service\Room\Binding\BindingKindRegistry;
use OCA\SendentSynchroniser\Service\Room\Binding\BindingValidator;
use PHPUnit\Framework\TestCase;

class BindingKindRegistryTest extends TestCase {

	public function testGetReturnsValidator(): void {
		$v = $this->createMock(BindingValidator::class);
		$v->method('kind')->willReturn('exchange');
		$reg = new BindingKindRegistry([$v]);
		$this->assertSame($v, $reg->get('exchange'));
	}

	public function testGetReturnsNullForUnknown(): void {
		$reg = new BindingKindRegistry([]);
		$this->assertNull($reg->get('martian'));
	}

	public function testKindsListsAllRegistered(): void {
		$v1 = $this->createMock(BindingValidator::class);
		$v1->method('kind')->willReturn('exchange');
		$v2 = $this->createMock(BindingValidator::class);
		$v2->method('kind')->willReturn('google');
		$reg = new BindingKindRegistry([$v1, $v2]);
		$this->assertSame(['exchange', 'google'], $reg->kinds());
	}
}
