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

	// Org-wide sign-in mode: how the sync service signs in to Microsoft Graph
	// for mailbox sync (CONTRACTS.md, Q3). The sync service reads it from the
	// paged /user/actives response as `signInMode` and compares the strings
	// byte for byte, so the values must match the contract exactly.
	//   serviceAccount   every user syncs through the service account (today's behaviour)
	//   perUserFallback  signed-in users sync with their own token; the rest keep
	//                    syncing through the service account until they sign in
	//   perUserStrict    signed-in users sync with their own token; the rest do not sync at all
	// The storage key name predates the "sign-in mode" wording and is kept as is.
	public const GRAPH_AUTH_METHOD_KEY = 'graphAuthMethod';
	public const GRAPH_AUTH_METHOD_SERVICE_ACCOUNT = 'serviceAccount';
	public const GRAPH_AUTH_METHOD_PER_USER_FALLBACK = 'perUserFallback';
	public const GRAPH_AUTH_METHOD_PER_USER_STRICT = 'perUserStrict';
	public const GRAPH_AUTH_METHOD_DEFAULT = self::GRAPH_AUTH_METHOD_SERVICE_ACCOUNT;

	// The accepted stored values. The setter's allowed list and the normaliser
	// below both read this, so they cannot drift.
	public const GRAPH_AUTH_METHODS = [
		self::GRAPH_AUTH_METHOD_SERVICE_ACCOUNT,
		self::GRAPH_AUTH_METHOD_PER_USER_FALLBACK,
		self::GRAPH_AUTH_METHOD_PER_USER_STRICT,
	];

	/**
	 * Normalises a stored sign-in mode. A known mode comes back as is; anything
	 * else (never set, the pre-rename 'perUserOAuth', an occ typo) becomes the
	 * default, so every existing install keeps today's behaviour. Both readers
	 * (Admin.php initial state and UserController::getActiveUsers) go through this.
	 */
	public static function normaliseGraphAuthMethod(string $stored): string {
		return in_array($stored, self::GRAPH_AUTH_METHODS, true)
			? $stored
			: self::GRAPH_AUTH_METHOD_DEFAULT;
	}

	// Address where users' browsers reach the sync service, used to build the
	// Microsoft sign-in start link. Must match DelegatedAuth:Graph:PublicBaseUrl
	// on the sync service. Stored without a trailing slash.
	public const SYNC_SERVICE_PUBLIC_URL_KEY = 'syncServicePublicUrl';
}
