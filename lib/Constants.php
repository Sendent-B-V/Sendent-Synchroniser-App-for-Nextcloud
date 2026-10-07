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

	// App-token names. Tokens minted before the architecture rework carry the
	// legacy name (the app id); new tokens carry TOKEN_NAME. A user still
	// holding a legacy-named token has not yet re-consented — that difference
	// drives the consent-modal push and the one-time calendar reset offer.
	public const TOKEN_NAME = 'sendent-synchronization';
	public const TOKEN_NAME_LEGACY = 'sendentsynchroniser';

	// Strip X-SENDENT* properties from personal-calendar events when they are
	// deleted into the calendar trash bin, so a restore yields a clean event.
	public const TRASHBIN_SCRUB_KEY = 'trashbinScrubEnabled';
	public const TRASHBIN_SCRUB_DEFAULT = 'false';
	public const SENDENT_PROPERTY_PREFIX = 'X-SENDENT';

	// Wire-format version of the /changes payload. Bump only on a breaking change.
	public const CN_FEED_VERSION = 1;

	// notify_push message name of the body-less hint; the Connector filters incoming frames on it.
	public const CN_MESSAGE_NAME = 'sendent_sync';
	public const CN_NOTIFY_PUSH_APPID = 'notify_push';

	public const COLLECTION_TYPE_CALDAV = 'caldav';
	public const COLLECTION_TYPE_CARDDAV = 'carddav';

	public const TRANSPORT_NOTIFY_PUSH = 'notify_push';
	public const TRANSPORT_POLLING = 'polling';

	// 'auto' offers notify_push whenever it is configured; 'polling' never does.
	public const CN_TRANSPORT_MODE_KEY = 'cnTransportMode';
	public const CN_TRANSPORT_MODE_DEFAULT = 'auto';
	public const CN_TRANSPORT_MODES = ['auto', self::TRANSPORT_POLLING];

	public const CN_BOT_USER_KEY = 'cnBotUser';

	// How often the Connector checks the feed while notify_push is unavailable;
	// the desktop client's equivalent is 30 s.
	public const CN_POLL_INTERVAL_KEY = 'cnPollInterval';
	public const CN_POLL_INTERVAL_DEFAULT = 30;
	public const CN_POLL_INTERVAL_MIN = 5;
	public const CN_POLL_INTERVAL_MAX = 300;

	// How far back the Connector re-reads /changes at the start of a check,
	// to absorb late-committing rows where no fence protects them.
	public const CN_REREAD_OVERLAP = 100;

	// Hard cap on ?limit= for /changes.
	public const CN_CHANGES_LIMIT_MAX = 1000;
	public const CN_CHANGES_LIMIT_DEFAULT = 500;

	// Durable lower bound for the cache counter: every value it legitimately
	// hands out is above this. A counter value at or below it means the counter
	// was lost (cache restart/eviction) or fell behind the DB fallback.
	public const CN_SEQ_FLOOR_KEY = 'cnSeqFloor';

	// Offset added to sndntsync_seq ids when there is no distributed cache.
	public const CN_SEQ_OFFSET_KEY = 'cnSeqOffset';
}
