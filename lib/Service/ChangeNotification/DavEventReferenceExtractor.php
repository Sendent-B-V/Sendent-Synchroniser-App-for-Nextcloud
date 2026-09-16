<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Constants;

/**
 * Turns a CalDAV/CardDAV collection row into a CollectionReference.
 * Deliberately free of Nextcloud types, so the payload-shape matrix across
 * NC 28-34 is unit-testable without a server.
 *
 * The principal comes from the collection row, never the acting session
 * user: a shared calendar edited by Bob has to signal Alice's collection.
 */
class DavEventReferenceExtractor {

	/** Only user principals map to a mailbox. */
	private const USER_PRINCIPAL_PREFIX = 'principals/users/';

	/** Sabre renders sync tokens as this URL in some payload shapes; stripped below for cross-version tolerance. */
	private const SYNC_TOKEN_PREFIX = 'http://sabre.io/ns/sync/';

	/** @param array<string, mixed>|null $row */
	public function fromCalendarRow(?array $row, bool $structural): ?CollectionReference {
		return $this->fromRow($row, Constants::COLLECTION_TYPE_CALDAV, $structural);
	}

	/** @param array<string, mixed>|null $row */
	public function fromAddressBookRow(?array $row, bool $structural): ?CollectionReference {
		return $this->fromRow($row, Constants::COLLECTION_TYPE_CARDDAV, $structural);
	}

	/** @param array<string, mixed>|null $row */
	private function fromRow(?array $row, string $type, bool $structural): ?CollectionReference {
		if (!is_array($row)) {
			return null;
		}

		$principal = $row['principaluri'] ?? null;
		$uri = $row['uri'] ?? null;

		if (!is_string($principal) || !is_string($uri) || $uri === '') {
			return null;
		}

		if (!str_starts_with($principal, self::USER_PRINCIPAL_PREFIX)
			|| $principal === self::USER_PRINCIPAL_PREFIX) {
			return null;
		}

		return new CollectionReference($principal, $type, $uri, $this->syncToken($row), $structural);
	}

	/** @param array<string, mixed> $row */
	private function syncToken(array $row): int {
		// Namespaced key is what OCA\DAV events carry; raw column is a defensive fallback.
		$raw = $row['synctoken'] ?? null;
		if ($raw === null) {
			$raw = $row['{http://sabredav.org/ns}sync-token'] ?? null;
		}

		if (is_object($raw)) {
			// Some backends hand back a Sabre property object rather than a scalar.
			$raw = method_exists($raw, 'getValue') ? $raw->getValue() : null;
		}

		if (is_string($raw) && str_starts_with($raw, self::SYNC_TOKEN_PREFIX)) {
			$raw = substr($raw, strlen(self::SYNC_TOKEN_PREFIX));
		}

		return is_numeric($raw) ? (int)$raw : 0;
	}
}
