<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service;

/**
 * Production NonceSource. Registered against the interface in Application.php
 * so the container can build StartLinkBuilder.
 */
final class RandomNonceSource implements NonceSource {

	public function bytes(int $length): string {
		return random_bytes($length);
	}
}
