<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\ChangeNotification;

use JsonSerializable;

/**
 * One "this collection changed" reference, as the change feed returns it.
 * Deliberately holds no calendar or contact data, and no sync token: the
 * Connector always syncs with the token it stored itself.
 */
final class CollectionReference implements JsonSerializable {

	public function __construct(
		public readonly string $principalUri,
		/** Constants::COLLECTION_TYPE_CALDAV or COLLECTION_TYPE_CARDDAV */
		public readonly string $collectionType,
		public readonly string $collectionUri,
		/** True when the collection itself changed (created/deleted/shared), not just an object in it. */
		public readonly bool $collectionChanged,
	) {}

	/** Identity of the collection, used for dedup and as the ledger's unique key. */
	public function key(): string {
		return $this->principalUri . "\x00" . $this->collectionType . "\x00" . $this->collectionUri;
	}

	/** @return array{p: string, t: string, u: string, c: bool} */
	public function jsonSerialize(): array {
		return [
			'p' => $this->principalUri,
			't' => $this->collectionType,
			'u' => $this->collectionUri,
			'c' => $this->collectionChanged,
		];
	}
}
