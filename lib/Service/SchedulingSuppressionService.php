<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service;

use OCA\SendentSynchroniser\Constants;
use OCA\SendentSynchroniser\Db\SyncUser;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCP\AppFramework\Services\IAppConfig;

class SchedulingSuppressionService {

	public function __construct(
		private IAppConfig $appConfig,
		private SyncUserMapper $syncUserMapper,
	) {}

	/**
	 * Decide whether to suppress Nextcloud's iTip processing for the given
	 * CalDAV request.
	 *
	 * Returns true ONLY when:
	 *   - Graph API mode is enabled in admin settings, AND
	 *   - $uid resolves to an active SyncUser, AND
	 *   - $requestPath targets that user's synced calendar.
	 *
	 * Fail-closed on path-parse ambiguity: if we cannot determine the
	 * calendar segment, we return false so the user still receives
	 * invitations from at least one source.
	 *
	 * @param string|null $uid           the authenticated NC user, or null
	 * @param string      $requestPath   Sabre request URI (e.g. "calendars/alice/exchange/1.ics")
	 */
	public function shouldSuppress(?string $uid, string $requestPath): bool {
		if ($this->appConfig->getAppValue(
				Constants::GRAPH_API_MODE_KEY,
				Constants::GRAPH_API_MODE_DEFAULT
			) !== 'true') {
			return false;
		}

		if ($uid === null || $uid === '') {
			return false;
		}

		$syncUsers = $this->syncUserMapper->findByUid($uid);
		if (count($syncUsers) === 0) {
			return false;
		}

		/** @var SyncUser $syncUser */
		$syncUser = $syncUsers[0];
		if (!$syncUser->getActive()) {
			return false;
		}

		$calendarUri = $this->extractCalendarUri($requestPath);
		if ($calendarUri === null) {
			return false;
		}

		return $calendarUri === $syncUser->getCalendar();
	}

	/**
	 * Extract the calendar URI segment from a CalDAV request path.
	 *
	 * Expected shape: "calendars/<uid>/<calendar-uri>/<event>.ics" (with or
	 * without leading slash). Returns null on any deviation.
	 */
	private function extractCalendarUri(string $requestPath): ?string {
		$trimmed = ltrim($requestPath, '/');
		$segments = explode('/', $trimmed);

		if (count($segments) < 3 || $segments[0] !== 'calendars') {
			return null;
		}

		$calendarUri = $segments[2];
		if ($calendarUri === '') {
			return null;
		}

		return $calendarUri;
	}
}
