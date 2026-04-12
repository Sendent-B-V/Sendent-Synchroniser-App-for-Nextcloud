<?php

namespace OCA\SendentSynchroniser\Service;

use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CardDAV\CardDavBackend;
use OCP\AppFramework\Services\IAppConfig;
use \Psr\Log\LoggerInterface;

class CollectionService {

	/** @var CalDavBackend */
	private $calDav;

	/** @var CardDavBackend */
	private $cardDav;

	/** @var IAppConfig */
	private $appConfig;

	/** @var LoggerInterface */
	private $logger;

	public const DEFAULT_CALENDAR_URI = 'personal';
	public const DEFAULT_CALENDAR_NAME = 'Personal';
	public const DEFAULT_ADDRESSBOOK_URI = 'contacts';
	public const DEFAULT_ADDRESSBOOK_NAME = 'Contacts';

	public function __construct(
		CalDavBackend $calDav,
		CardDavBackend $cardDav,
		IAppConfig $appConfig,
		LoggerInterface $logger
	) {
		$this->calDav = $calDav;
		$this->cardDav = $cardDav;
		$this->appConfig = $appConfig;
		$this->logger = $logger;
	}

	/**
	 * Returns a principal URI for the given user ID.
	 */
	private function principal(string $userId): string {
		return 'principals/users/' . $userId;
	}

	// ─── Calendars ─────────────────��─────────────────────────────

	/**
	 * Lists all VEVENT-capable calendars for a user.
	 *
	 * @return array [ { 'id' => int, 'uri' => string, 'displayName' => string }, ... ]
	 */
	public function getUserCalendars(string $userId): array {
		$calendars = $this->calDav->getCalendarsForUser($this->principal($userId));
		$result = [];

		foreach ($calendars as $cal) {
			// Only include calendars that support VEVENT
			if (isset($cal['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'])) {
				$components = $cal['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set']->getValue();
				if (!in_array('VEVENT', $components)) {
					continue;
				}
			}

			// Skip deleted calendars
			if (isset($cal['{http://nextcloud.com/ns}deleted-at'])
				&& is_numeric($cal['{http://nextcloud.com/ns}deleted-at'])) {
				continue;
			}

			$result[] = [
				'id' => $cal['id'],
				'uri' => $cal['uri'],
				'displayName' => $cal['{DAV:}displayname'] ?? $cal['uri'],
			];
		}

		return $result;
	}

	/**
	 * Creates a calendar for the user if it doesn't exist.
	 *
	 * @return int|null The calendar ID, or null on failure
	 */
	public function createCalendar(string $userId, string $uri, string $displayName): ?int {
		$existing = $this->getUserCalendars($userId);
		foreach ($existing as $cal) {
			if ($cal['uri'] === $uri) {
				return $cal['id'];
			}
		}

		$this->logger->info('Creating calendar "' . $uri . '" for user "' . $userId . '"');
		$id = $this->calDav->createCalendar($this->principal($userId), $uri, [
			'{DAV:}displayname' => $displayName,
			'components' => 'VEVENT',
		]);

		return $id ?: null;
	}

	/**
	 * Ensures a calendar with the given URI exists for the user.
	 */
	public function ensureCalendar(string $userId, string $uri, string $displayName): void {
		$this->createCalendar($userId, $uri, $displayName);
	}

	// ─── Addressbooks ────────────────────────────��───────────────

	/**
	 * Lists all addressbooks for a user.
	 *
	 * @return array [ { 'id' => int, 'uri' => string, 'displayName' => string }, ... ]
	 */
	public function getUserAddressbooks(string $userId): array {
		$books = $this->cardDav->getAddressBooksForUser($this->principal($userId));
		$result = [];

		foreach ($books as $book) {
			// Skip deleted addressbooks
			if (isset($book['{http://nextcloud.com/ns}deleted-at'])
				&& is_numeric($book['{http://nextcloud.com/ns}deleted-at'])) {
				continue;
			}

			$result[] = [
				'id' => $book['id'],
				'uri' => $book['uri'],
				'displayName' => $book['{DAV:}displayname'] ?? $book['uri'],
			];
		}

		return $result;
	}

	/**
	 * Creates an addressbook for the user if it doesn't exist.
	 *
	 * @return int|null The addressbook ID, or null on failure
	 */
	public function createAddressbook(string $userId, string $uri, string $displayName): ?int {
		$existing = $this->getUserAddressbooks($userId);
		foreach ($existing as $book) {
			if ($book['uri'] === $uri) {
				return $book['id'];
			}
		}

		$this->logger->info('Creating addressbook "' . $uri . '" for user "' . $userId . '"');
		$id = $this->cardDav->createAddressBook($this->principal($userId), $uri, [
			'{DAV:}displayname' => $displayName,
		]);

		return $id ?: null;
	}

	/**
	 * Ensures an addressbook with the given URI exists for the user.
	 */
	public function ensureAddressbook(string $userId, string $uri, string $displayName): void {
		$this->createAddressbook($userId, $uri, $displayName);
	}

	// ─── Per-group default resolution ────────────────────────────

	/**
	 * Resolves the default calendar URI for a given set of group IDs.
	 * Returns the first matching group's setting, or NC default.
	 *
	 * @param string[] $groupIds The user's active group IDs
	 */
	public function getDefaultCalendarForGroups(array $groupIds): string {
		$map = json_decode($this->appConfig->getAppValue('defaultCalendars', '{}'), true) ?: [];
		foreach ($groupIds as $gid) {
			if (!empty($map[$gid])) {
				return $map[$gid];
			}
		}
		return self::DEFAULT_CALENDAR_URI;
	}

	/**
	 * Resolves the default addressbook URI for a given set of group IDs.
	 *
	 * @param string[] $groupIds The user's active group IDs
	 */
	public function getDefaultAddressbookForGroups(array $groupIds): string {
		$map = json_decode($this->appConfig->getAppValue('defaultAddressbooks', '{}'), true) ?: [];
		foreach ($groupIds as $gid) {
			if (!empty($map[$gid])) {
				return $map[$gid];
			}
		}
		return self::DEFAULT_ADDRESSBOOK_URI;
	}

	// ─── Combined ────────────────────────────────────────────────

	/**
	 * Ensures the default collections exist for a user.
	 *
	 * Always ensures the Nextcloud defaults (personal / contacts) exist first —
	 * other NC apps depend on these. Then, if a per-group custom default is set,
	 * additionally ensures that collection exists.
	 *
	 * @param string $userId
	 * @param string[] $groupIds The user's active sync group IDs (for per-group default resolution)
	 */
	public function ensureDefaultCollections(string $userId, array $groupIds = []): void {
		// Always ensure NC defaults exist (LDAP/SAML users may not have them)
		$calendars = $this->getUserCalendars($userId);
		$calendarUris = array_column($calendars, 'uri');
		if (!in_array(self::DEFAULT_CALENDAR_URI, $calendarUris)) {
			$this->createCalendar($userId, self::DEFAULT_CALENDAR_URI, self::DEFAULT_CALENDAR_NAME);
		}

		$addressbooks = $this->getUserAddressbooks($userId);
		$addressbookUris = array_column($addressbooks, 'uri');
		if (!in_array(self::DEFAULT_ADDRESSBOOK_URI, $addressbookUris)) {
			$this->createAddressbook($userId, self::DEFAULT_ADDRESSBOOK_URI, self::DEFAULT_ADDRESSBOOK_NAME);
		}

		// If a per-group custom default is configured, ensure it exists too
		$groupCalUri = $this->getDefaultCalendarForGroups($groupIds);
		if ($groupCalUri !== self::DEFAULT_CALENDAR_URI && !in_array($groupCalUri, $calendarUris)) {
			$this->createCalendar($userId, $groupCalUri, ucfirst($groupCalUri));
		}

		$groupAbUri = $this->getDefaultAddressbookForGroups($groupIds);
		if ($groupAbUri !== self::DEFAULT_ADDRESSBOOK_URI && !in_array($groupAbUri, $addressbookUris)) {
			$this->createAddressbook($userId, $groupAbUri, ucfirst($groupAbUri));
		}
	}
}
