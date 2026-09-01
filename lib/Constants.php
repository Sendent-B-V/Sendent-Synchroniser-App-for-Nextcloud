<?php

namespace OCA\SendentSynchroniser;

class Constants {

	public const USER_STATUS_INACTIVE =0;
	public const USER_STATUS_ACTIVE =1;
	public const USER_STATUS_NOCONSENT =2;

	public const REMINDER_MODAL = 1;
	public const REMINDER_NOTIFICATIONS = 2;
	public const REMINDER_BOTH = 3;
	public const REMINDER_DEFAULT_TYPE = self::REMINDER_NOTIFICATIONS;
	public const REMINDER_NOTIFICATIONS_DEFAULT_INTERVAL = 7;

	public const NOTIFICATIONMETHOD_MODAL_GROUPWARE = 1;
	public const NOTIFICATIONMETHOD_MODAL_FILE = 2;
	public const NOTIFICATIONMETHOD_MODAL_BOTH = 3;
	public const NOTIFICATIONMETHOD_MODAL_DEFAULT = self::NOTIFICATIONMETHOD_MODAL_FILE;

	// Storage key value stays 'graphApiMode' to preserve existing admin settings across the rename.
	public const DISABLE_ITIP_IMIP_KEY = 'graphApiMode';
	public const DISABLE_ITIP_IMIP_DEFAULT = 'true';

	// Strip X-SENDENT* properties from personal-calendar events when they are
	// deleted into the calendar trash bin, so a restore yields a clean event.
	public const TRASHBIN_SCRUB_KEY = 'trashbinScrubEnabled';
	public const TRASHBIN_SCRUB_DEFAULT = 'false';
	public const SENDENT_PROPERTY_PREFIX = 'X-SENDENT';

	// ─── Change notifications ────────────────────────────────────────────
	// Wire-format version of the signal payload. Bump only on a breaking change.
	public const CN_SIGNAL_VERSION = 1;

	// notify_push message name; the Connector filters incoming frames on it.
	public const CN_MESSAGE_NAME = 'sendent_sync';
	public const CN_PING_MESSAGE_NAME = 'sendent_sync_ping';
	public const CN_NOTIFY_PUSH_APPID = 'notify_push';

	public const COLLECTION_TYPE_CALDAV = 'caldav';
	public const COLLECTION_TYPE_CARDDAV = 'carddav';

	public const TRANSPORT_NOTIFY_PUSH = 'notify_push';
	public const TRANSPORT_POLLING = 'polling';

	// 'auto' prefers notify_push and falls back; the other two pin a transport.
	public const CN_TRANSPORT_MODE_KEY = 'cnTransportMode';
	public const CN_TRANSPORT_MODE_DEFAULT = 'auto';
	public const CN_TRANSPORT_MODES = ['auto', self::TRANSPORT_NOTIFY_PUSH, self::TRANSPORT_POLLING];

	public const CN_BOT_USER_KEY = 'cnBotUser';

	// Informational: where the Exchange Connector runs. Nextcloud never calls
	// this URL (the Connector connects inbound; the optional webhook has its
	// own URL) — it is shown in diagnostics so support can find the peer.
	public const CN_CONNECTOR_URL_KEY = 'cnConnectorUrl';

	public const CN_BATCH_WINDOW_KEY = 'cnBatchWindow';
	public const CN_BATCH_WINDOW_DEFAULT = 2;
	public const CN_BATCH_WINDOW_MIN = 0;
	public const CN_BATCH_WINDOW_MAX = 10;

	public const CN_MAX_REFS_KEY = 'cnMaxRefsPerSignal';
	public const CN_MAX_REFS_DEFAULT = 500;
	public const CN_MAX_REFS_MIN = 1;
	public const CN_MAX_REFS_MAX = 5000;

	public const CN_POLL_INTERVAL_KEY = 'cnPollInterval';
	public const CN_POLL_INTERVAL_DEFAULT = 30;
	public const CN_POLL_INTERVAL_MIN = 5;
	public const CN_POLL_INTERVAL_MAX = 300;

	// How far back the Connector re-reads /changes to absorb late-committing rows.
	public const CN_REREAD_OVERLAP_KEY = 'cnRereadOverlap';
	public const CN_REREAD_OVERLAP_DEFAULT = 100;

	// Hard cap on ?limit= for /changes.
	public const CN_CHANGES_LIMIT_MAX = 1000;
	public const CN_CHANGES_LIMIT_DEFAULT = 500;

	// Watermark of the last sequence number a live signal carried.
	public const CN_FLUSHED_SEQ_KEY = 'cnFlushedSeq';

	// Offset added to sndntsync_seq ids when there is no distributed cache.
	public const CN_SEQ_OFFSET_KEY = 'cnSeqOffset';

	// Cached daemon reachability probe: {"ok":bool,"at":int,"message":string}
	public const CN_DAEMON_CHECK_KEY = 'cnDaemonCheck';
	public const CN_DAEMON_CHECK_TTL = 300;

	// Browser round-trip result: {"ok":bool,"at":int,"ms":int}
	public const CN_ROUND_TRIP_KEY = 'cnRoundTrip';

	public const CN_ACK_CURSOR_KEY = 'cnAckCursor';
	public const CN_ACK_AT_KEY = 'cnAckAt';
	public const CN_LAST_SIGNAL_AT_KEY = 'cnLastSignalAt';

	public const CN_WEBHOOK_ENABLED_KEY = 'cnWebhookEnabled';
	public const CN_WEBHOOK_URL_KEY = 'cnWebhookUrl';
	public const CN_WEBHOOK_SECRET_KEY = 'cnWebhookSecret';

	public const CN_ALLOWLIST_ENABLED_KEY = 'cnAllowListEnabled';
	public const CN_ALLOWLIST_KEY = 'cnAllowList';
}
