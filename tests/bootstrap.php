<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

require_once __DIR__ . '/../../../tests/bootstrap.php';

// The listener tests construct real OCA\DAV event objects, but the server's
// test bootstrap does not register app autoloaders. Try the app loader, then
// fall back to requiring the DAV app's own composer autoloader (covers server
// setups where OC_App is unavailable or app loading is not initialised), and
// complain loudly rather than letting five tests fail with class-not-found.
if (!class_exists(\OCA\DAV\Events\CalendarObjectCreatedEvent::class)) {
	if (class_exists(\OC_App::class)) {
		try {
			\OC_App::loadApp('dav');
		} catch (\Throwable $e) {
			// fall through to the direct require below
		}
	}
}
if (!class_exists(\OCA\DAV\Events\CalendarObjectCreatedEvent::class)) {
	foreach ([
		__DIR__ . '/../../dav/composer/autoload.php',        // app in apps/
		__DIR__ . '/../../../apps/dav/composer/autoload.php', // app in custom_apps/
	] as $davAutoload) {
		if (file_exists($davAutoload)) {
			require_once $davAutoload;
			break;
		}
	}
}
if (!class_exists(\OCA\DAV\Events\CalendarObjectCreatedEvent::class)) {
	fwrite(STDERR, "sendentsynchroniser tests: could not load the dav app — OCA\\DAV event classes will be missing\n");
}
