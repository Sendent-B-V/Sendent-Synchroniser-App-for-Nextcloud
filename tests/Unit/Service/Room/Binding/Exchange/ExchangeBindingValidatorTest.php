<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Service\Room\Binding\Exchange;

use OCA\SendentSynchroniser\Service\Room\Binding\BindingValidationException;
use OCA\SendentSynchroniser\Service\Room\Binding\Exchange\ExchangeBindingValidator;
use PHPUnit\Framework\TestCase;

class ExchangeBindingValidatorTest extends TestCase {
	public function testKind(): void {
		$this->assertSame('exchange', (new ExchangeBindingValidator())->kind());
	}

	public function testAcceptsValidSmtp(): void {
		$v = new ExchangeBindingValidator();
		$out = $v->validate('boardroom-a@contoso.com', []);
		$this->assertSame('boardroom-a@contoso.com', $out['externalId']);
		$this->assertSame([], $out['config']);
	}

	public function testLowercasesExternalId(): void {
		$v = new ExchangeBindingValidator();
		$out = $v->validate('Boardroom-A@CONTOSO.COM', []);
		$this->assertSame('boardroom-a@contoso.com', $out['externalId']);
	}

	public function testRejectsEmpty(): void {
		$this->expectException(BindingValidationException::class);
		(new ExchangeBindingValidator())->validate('', []);
	}

	public function testRejectsNonEmail(): void {
		$this->expectException(BindingValidationException::class);
		(new ExchangeBindingValidator())->validate('not-an-email', []);
	}
}
