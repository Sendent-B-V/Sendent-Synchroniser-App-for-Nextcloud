<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service;

/**
 * base64url without padding (RFC 4648 section 5), the encoding the sync
 * service expects for the nonce and signature of the Microsoft sign-in start
 * link. The status callback from the service uses the same encoding; reuse
 * this class there instead of copying these lines.
 *
 * Stateless, so static: nothing to inject, nothing to mock.
 */
final class Base64Url {

	/**
	 * Raw bytes in, base64url text without '=' padding out.
	 * 16 bytes become 22 characters, 32 bytes become 43.
	 */
	public static function encode(string $bytes): string {
		$standard = base64_encode($bytes);
		$urlSafe = strtr($standard, '+/', '-_');
		return rtrim($urlSafe, '=');
	}

	/**
	 * Inverse of encode(). Returns null when the text is not valid base64url,
	 * so a caller can refuse bad input instead of getting an empty string.
	 */
	public static function decode(string $text): ?string {
		$standard = strtr($text, '-_', '+/');
		$missing = (4 - strlen($standard) % 4) % 4;
		$padded = $standard . str_repeat('=', $missing);

		$bytes = base64_decode($padded, true);
		if ($bytes === false) {
			return null;
		}
		return $bytes;
	}
}
