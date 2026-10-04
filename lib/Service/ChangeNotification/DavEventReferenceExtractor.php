<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Constants;

/**
 * Turns a CalDAV/CardDAV collection row from a DAV event into a
 * CollectionReference. Free of Nextcloud types, so it is unit-testable
 * without a server.
 *
 * The principal comes from the collection row, never the acting session
 * user: a shared calendar edited by Bob changes Alice's collection.
 */
class DavEventReferenceExtractor {

	/** Only user principals map to a mailbox. */
	private const USER_PRINCIPAL_PREFIX = 'principals/users/';

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

		return new CollectionReference($principal, $type, $uri, $structural);
	}
}
