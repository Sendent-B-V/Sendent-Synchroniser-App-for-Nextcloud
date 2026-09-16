<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\Db;

use OCA\SendentSynchroniser\Db\DirtyCollection;
use PHPUnit\Framework\TestCase;

class DirtyCollectionTest extends TestCase {

	private function row(int $structuralSeq = 0): DirtyCollection {
		$entity = new DirtyCollection();
		$entity->setPrincipalUri('principals/users/alice');
		$entity->setCollectionType('caldav');
		$entity->setCollectionUri('personal');
		$entity->setSyncToken(9651);
		$entity->setChangeSeq(1849233);
		$entity->setStructuralSeq($structuralSeq);
		$entity->setUpdatedAt(1755676800);
		return $entity;
	}

	public function testToReferenceCarriesTheRowFields(): void {
		$ref = $this->row()->toReference(0);

		$this->assertSame('principals/users/alice', $ref->principalUri);
		$this->assertSame('caldav', $ref->collectionType);
		$this->assertSame('personal', $ref->collectionUri);
		$this->assertSame(9651, $ref->syncToken);
	}

	public function testCollectionChangedIsTrueWhenTheStructuralChangeIsNewerThanSince(): void {
		$this->assertTrue($this->row(500)->toReference(499)->collectionChanged);
	}

	public function testCollectionChangedIsFalseWhenTheStructuralChangeIsAlreadySeen(): void {
		$this->assertFalse($this->row(500)->toReference(500)->collectionChanged);
		$this->assertFalse($this->row(500)->toReference(900)->collectionChanged);
	}

	public function testCollectionChangedIsFalseWhenThereNeverWasAStructuralChange(): void {
		$this->assertFalse($this->row(0)->toReference(0)->collectionChanged);
	}
}
