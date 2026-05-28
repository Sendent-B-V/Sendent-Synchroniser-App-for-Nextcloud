<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Sabre;

use OCA\SendentSynchroniser\Service\SchedulingSuppressionService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\VObject\ITip\Message;

/**
 * Suppresses Nextcloud's outbound iMIP and internal CalDAV iTip delivery
 * when both gates pass:
 *
 *  - USER GATE — toggle on AND user is in an active Sendent group
 *                (delegated to SchedulingSuppressionService).
 *  - CALENDAR GATE — the calendar URI parsed from the current CalDAV
 *                    request matches the user's default calendar resolved
 *                    from SCHEDULE_DEFAULT_CALENDAR_PROP — what the NC
 *                    Calendar app writes when the user picks "Default
 *                    calendar for incoming invitations".
 *
 * Outbox/free-busy routes (request URI not under `calendars/{uid}/{cal}/`)
 * and users who haven't picked a default in NC Calendar (property unset)
 * both fall through to NC's normal scheduling. No IConfig
 * `dav.defaultCalendar` fallback.
 */
class SchedulingSuppressorPlugin extends ServerPlugin {

	/**
	 * Priority must be lower (= called earlier) than IMipPlugin's. IMipPlugin
	 * registers at priority 100 in NC 28-33.
	 */
	public const SCHEDULE_PRIORITY = 50;

	private const SCHEDULE_DEFAULT_CALENDAR_PROP = '{urn:ietf:params:xml:ns:caldav}schedule-default-calendar-URL';

	private Server $server;

	/**
	 * Per-request memoization of resolved default-calendar URIs keyed by uid.
	 * A multi-attendee meeting triggers one `schedule` event per attendee;
	 * the cache collapses N property lookups into one per unique uid.
	 * Resets implicitly between requests since plugin is instantiated fresh.
	 *
	 * @var array<string, string|null>
	 */
	private array $resolvedDefaultByUid = [];

	public function __construct(
		private SchedulingSuppressionService $suppressionService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {}

	public function initialize(Server $server): void {
		$server->on('schedule', [$this, 'onSchedule'], self::SCHEDULE_PRIORITY);
		$this->server = $server;
	}

	public function onSchedule(Message $iTipMessage): ?bool {
		$user = $this->userSession->getUser();
		$uid = $user?->getUID();
		$requestPath = $this->server->getRequestUri();

		if (!$this->suppressionService->shouldSuppress($uid, $requestPath)) {
			return null;
		}

		$currentCalendarUri = $this->extractCalendarUriFromPath($requestPath);
		if ($currentCalendarUri === null || $uid === null || $uid === '') {
			return null;
		}

		$defaultUri = $this->resolveDefaultCalendarFromProp($uid);
		if ($defaultUri === null || $currentCalendarUri !== $defaultUri) {
			return null;
		}

		$iTipMessage->scheduleStatus = '2.0;Success - suppressed by Sendent Sync (Disable ITip and IMip)';
		return false;
	}

	/**
	 * Resolves the user's default calendar URI segment from the CalDAV
	 * principal property (`schedule-default-calendar-URL`). Memoized per uid
	 * for the lifetime of the request so N-attendee meetings don't trigger
	 * N property lookups.
	 */
	private function resolveDefaultCalendarFromProp(string $uid): ?string {
		if (array_key_exists($uid, $this->resolvedDefaultByUid)) {
			return $this->resolvedDefaultByUid[$uid];
		}

		$raw = null;
		try {
			$props = $this->server->getProperties(
				'principals/users/' . $uid,
				[self::SCHEDULE_DEFAULT_CALENDAR_PROP],
			);
			$prop = $props[self::SCHEDULE_DEFAULT_CALENDAR_PROP] ?? null;
			if (is_string($prop)) {
				$raw = $prop;
			} elseif (is_object($prop) && method_exists($prop, 'getHref')) {
				$raw = (string)$prop->getHref();
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to read schedule-default-calendar-URL for {uid}: {msg}', [
				'uid' => $uid,
				'msg' => $e->getMessage(),
				'app' => 'sendentsynchroniser',
			]);
		}

		$parsed = ($raw !== null && $raw !== '') ? $this->extractCalendarUriFromPath($raw) : null;
		return $this->resolvedDefaultByUid[$uid] = $parsed;
	}

	/**
	 * Extracts the calendar URI segment from a Sabre path like
	 * `calendars/alice/personal/abc.ics`, `calendars/alice/personal/`, or
	 * `/remote.php/dav/calendars/alice/personal/`. Returns null on outbox/
	 * free-busy/principal paths and anything else that isn't under
	 * `calendars/{principal}/{calendarUri}/...`.
	 */
	private function extractCalendarUriFromPath(string $value): ?string {
		if (preg_match('#/remote\.php/dav/(.*)#', $value, $m)) {
			$value = $m[1];
		}
		$value = trim($value, '/');
		$segments = explode('/', $value);
		if (count($segments) >= 3 && $segments[0] === 'calendars') {
			return $segments[2];
		}
		return null;
	}

	public function getPluginName(): string {
		return 'sendentsynchroniser-scheduling-suppressor';
	}
}
