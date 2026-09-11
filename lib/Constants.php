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
}
