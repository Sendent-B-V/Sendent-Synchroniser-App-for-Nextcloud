<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

require_once __DIR__ . '/../../../tests/bootstrap.php';

// The listener tests construct real OCA\DAV event objects, but the server's
// test bootstrap does not register app autoloaders — load the DAV app so its
// classes resolve.
if (class_exists(\OC_App::class)) {
	\OC_App::loadApp('dav');
}
