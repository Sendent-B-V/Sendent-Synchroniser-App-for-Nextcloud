<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

require_once __DIR__ . '/../../../tests/bootstrap.php';

// The listener tests construct real OCA\DAV event objects, but the server's
// test bootstrap does not register app autoloaders. Try the app loader, then
// the DAV app's own composer autoloader.
if (!class_exists(\OCA\DAV\Events\CalendarCreatedEvent::class) && class_exists(\OC_App::class)) {
	try {
		\OC_App::loadApp('dav');
	} catch (\Throwable $e) {
		// fall through to the autoloader below
	}
}
if (!class_exists(\OCA\DAV\Events\CalendarCreatedEvent::class)) {
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
if (!class_exists(\OCA\DAV\Events\CalendarCreatedEvent::class)) {
	fwrite(STDERR, "sendentsynchroniser tests: could not load the dav app — the DAV listener tests will fail with missing OCA\\DAV event classes\n");
}
