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
 *
 * Permanent deletes from the calendar trash bin (`calendars/{uid}/trashbin/{id}`)
 * are a special case: NC regenerates a CANCEL on purge, but the path carries no
 * real calendar name, so the CALENDAR GATE resolves the trashed event's origin
 * calendar from the node itself before comparing to the default.
 */
class SchedulingSuppressorPlugin extends ServerPlugin {

	/**
	 * Priority must be lower (= called earlier) than IMipPlugin's. IMipPlugin
	 * registers at priority 100 in NC 28-33.
	 */
	public const SCHEDULE_PRIORITY = 50;

	private const SCHEDULE_DEFAULT_CALENDAR_PROP = '{urn:ietf:params:xml:ns:caldav}schedule-default-calendar-URL';

	/**
	 * Calendar-home child that holds soft-deleted events (NC calendar trash bin).
	 * A permanent delete from the trash targets `calendars/{uid}/trashbin/{id}`,
	 * so extractCalendarUriFromPath() yields this literal instead of a real
	 * calendar name — signalling we must resolve the event's origin calendar.
	 */
	private const TRASHBIN_URI_SEGMENT = 'trashbin';

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

		if ($uid === null || $uid === '') {
			return null;
		}

		$currentCalendarUri = $this->extractCalendarUriFromPath($requestPath);

		// Permanent delete from the calendar trash bin. NC's DeletedCalendarObject
		// implements ICalendarObject, so Sabre's beforeUnbind regenerates a CANCEL on
		// purge (server#36051) — but the request path is calendars/{uid}/trashbin/{id},
		// whose calendar segment is the literal "trashbin", not the event's origin
		// calendar. Resolve the trashed node's origin so the default-calendar gate below
		// still applies and mirrors what happened at move-to-trash time.
		if ($currentCalendarUri === self::TRASHBIN_URI_SEGMENT) {
			$currentCalendarUri = $this->resolveTrashbinOriginCalendarUri($requestPath);
		}

		if ($currentCalendarUri === null) {
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
	 * Resolves the origin calendar URI of a trashed calendar object from its
	 * trash-bin path (`calendars/{uid}/trashbin/{id}`). NC's DeletedCalendarObject
	 * exposes it via getCalendarUri()/getSourceCalendarUri(); we duck-type so we
	 * neither depend on the class nor break if it is absent on older NC. Returns
	 * null when the node can't be loaded or isn't a recognisable trash object —
	 * failing safe to NC's normal scheduling rather than over-suppressing.
	 */
	private function resolveTrashbinOriginCalendarUri(string $requestPath): ?string {
		try {
			$node = $this->server->tree->getNodeForPath($requestPath);
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to load trash-bin node {path}: {msg}', [
				'path' => $requestPath,
				'msg' => $e->getMessage(),
				'app' => 'sendentsynchroniser',
			]);
			return null;
		}

		foreach (['getCalendarUri', 'getSourceCalendarUri'] as $method) {
			if (method_exists($node, $method)) {
				$uri = $node->$method();
				if (is_string($uri) && $uri !== '') {
					return $uri;
				}
			}
		}

		return null;
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
			// Normalise percent-encoding: the request path may arrive decoded
			// (`persönlich`) while the schedule-default-calendar-URL href is
			// encoded (`pers%C3%B6nlich`), or vice versa. rawurldecode() collapses
			// both to the same form so non-ASCII / localized slugs still match.
			// No-op for plain ASCII slugs like `personal`. See NC server#40512.
			return rawurldecode($segments[2]);
		}
		return null;
	}

	public function getPluginName(): string {
		return 'sendentsynchroniser-scheduling-suppressor';
	}
}
