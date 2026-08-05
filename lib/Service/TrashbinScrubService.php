<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service;

use OCA\SendentSynchroniser\Constants;
use OCP\AppFramework\Services\IAppConfig;
use Sabre\VObject\Component;
use Sabre\VObject\Property;
use Sabre\VObject\Reader;

/**
 * Strips Sendent custom iCalendar properties (X-SENDENT*) from calendar data.
 *
 * Walks every component recursively — NOT getBaseComponents(), which skips
 * RECURRENCE-ID override VEVENTs — so overrides and VALARMs are covered too.
 * VTIMEZONE is untouched simply because it never carries X-SENDENT properties.
 */
class TrashbinScrubService {

	public function __construct(
		private IAppConfig $appConfig,
	) {}

	/**
	 * Admin toggle for the trash-bin scrub. Off by default so behavior only
	 * changes once an admin opts in.
	 */
	public function isEnabled(): bool {
		return $this->appConfig->getAppValue(
			Constants::TRASHBIN_SCRUB_KEY,
			Constants::TRASHBIN_SCRUB_DEFAULT
		) === 'true';
	}

	/**
	 * Removes every property whose name starts with X-SENDENT (any casing,
	 * any component depth) and returns the re-serialized iCalendar data.
	 * Returns null when there is nothing to strip, so callers can skip the
	 * write-back entirely.
	 */
	public function stripSendentProperties(string $calendarData): ?string {
		// Cheap pre-parse rejection for the overwhelmingly common case.
		if (stripos($calendarData, Constants::SENDENT_PROPERTY_PREFIX) === false) {
			return null;
		}

		$vObject = Reader::read($calendarData);
		if (!$this->stripComponent($vObject)) {
			// Prefix matched somewhere in the raw text (e.g. inside a value)
			// but no property carries it as a name.
			return null;
		}

		return $vObject->serialize();
	}

	/**
	 * children() returns a freshly built snapshot array, so remove() while
	 * iterating is safe. Parsed property names are uppercase-normalized by
	 * sabre/vobject (RFC 5545 names are case-insensitive); strtoupper() is
	 * kept as a defensive no-op.
	 */
	private function stripComponent(Component $component): bool {
		$removed = false;
		foreach ($component->children() as $child) {
			if ($child instanceof Property
				&& str_starts_with(strtoupper($child->name), Constants::SENDENT_PROPERTY_PREFIX)) {
				$component->remove($child);
				$removed = true;
			} elseif ($child instanceof Component) {
				$removed = $this->stripComponent($child) || $removed;
			}
		}
		return $removed;
	}
}
