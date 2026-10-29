<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service;

/**
 * Where the start link gets its nonce bytes from.
 *
 * Exists so the shared test vector can be asserted byte for byte: the test
 * swaps in a fake that returns bytes 0x00..0x0F. Nextcloud's ISecureRandom cannot
 * play this role, it hands out characters from an alphabet, never raw bytes.
 */
interface NonceSource {

	/**
	 * Exactly $length raw bytes from a cryptographic generator.
	 */
	public function bytes(int $length): string;
}
