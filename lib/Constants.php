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

	/**
	 * Master switch for the rooms subsystem (resource calendar backend, room
	 * user backend, BindingKindRegistry, room CalDAV scheduling plugin, REST
	 * routes). All room source files, Vue components, tests, and DB tables
	 * stay in place — flipping this back to true re-attaches the wiring at:
	 *   - lib/AppInfo/Application.php           (calendar/user backends + registry)
	 *   - lib/Listener/SabrePluginRegistrationListener.php  (room scheduling plugin)
	 *   - appinfo/routes.php                    (room REST routes)
	 *   - appinfo/info.xml                      (room occ commands — uncomment block)
	 */
	public const ROOMS_FEATURE_ENABLED = false;
}
