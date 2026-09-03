<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\ChangeNotification;

use JsonSerializable;

/**
 * One "this collection changed" reference — the only thing a signal ever
 * carries. Deliberately holds no calendar or contact data: principals, URIs
 * and integers only.
 *
 * Wire field names are single letters (roughly 90 bytes per ref) so a 500-ref
 * signal stays around 45 KB.
 */
final class CollectionReference implements JsonSerializable {

	public function __construct(
		public readonly string $principalUri,
		/** Constants::COLLECTION_TYPE_CALDAV or COLLECTION_TYPE_CARDDAV */
		public readonly string $collectionType,
		public readonly string $collectionUri,
		public readonly int $syncToken,
		/** True when the collection itself changed (created/deleted/shared), not just an object in it. */
		public readonly bool $collectionChanged,
	) {}

	/** Identity of the collection, used for dedup and as the ledger's unique key. */
	public function key(): string {
		return $this->principalUri . "\x00" . $this->collectionType . "\x00" . $this->collectionUri;
	}

	public function withCollectionChanged(bool $changed): self {
		return new self(
			$this->principalUri,
			$this->collectionType,
			$this->collectionUri,
			$this->syncToken,
			$changed
		);
	}

	/** @return array{p: string, t: string, u: string, s: int, c: bool} */
	public function jsonSerialize(): array {
		return [
			'p' => $this->principalUri,
			't' => $this->collectionType,
			'u' => $this->collectionUri,
			's' => $this->syncToken,
			'c' => $this->collectionChanged,
		];
	}
}
