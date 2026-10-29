<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service;

use OCA\SendentSynchroniser\Constants;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Builds the signed Microsoft sign-in start link the sync service verifies:
 *
 *   {PublicBaseUrl}/auth/{provider}/start?user={user}&ts={ts}&nonce={nonce}&sig={sig}
 *
 * sig is HMAC-SHA256 over "{provider}\n{user}\n{ts}\n{nonce}" keyed with the
 * shared secret; nonce is 16 random bytes; both are base64url without
 * padding. The service checks the signature, a 300 second max age, and that
 * the nonce was never seen before.
 *
 * The clock and the nonce bytes are injected so the unit test can reproduce
 * the shared test vector exactly. This class decides whether a link may be built
 * at all (sign-in mode, public URL, shared secret); the controller only maps
 * the outcome to an HTTP response.
 */
class StartLinkBuilder {

	public const PROVIDER_GRAPH = 'graph';
	public const NONCE_BYTES = 16;

	public function __construct(
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private NonceSource $nonceSource,
	) {
	}

	/**
	 * @param string $userId the Nextcloud uid, never the display name or email
	 * @return string the full link, ready to redirect the browser to
	 * @throws StartLinkUnavailableException when the mode or the settings refuse it
	 */
	public function build(string $userId, string $provider = self::PROVIDER_GRAPH): string {
		// Refusals come first so no clock or randomness is consumed for a link
		// that will never be issued.
		$storedMode = $this->appConfig->getAppValue(Constants::GRAPH_AUTH_METHOD_KEY, '');
		$mode = Constants::normaliseGraphAuthMethod($storedMode);
		if ($mode === Constants::GRAPH_AUTH_METHOD_SERVICE_ACCOUNT) {
			throw new StartLinkUnavailableException(StartLinkUnavailableException::REASON_SERVICE_ACCOUNT);
		}

		$publicUrl = $this->appConfig->getAppValue(Constants::SYNC_SERVICE_PUBLIC_URL_KEY, '');
		if ($publicUrl === '') {
			throw new StartLinkUnavailableException(StartLinkUnavailableException::REASON_PUBLIC_URL);
		}
		$baseUrl = rtrim($publicUrl, '/');

		// The key is the secret exactly as configured, no trim, no hashing; the
		// service uses the same raw bytes.
		$secret = $this->appConfig->getAppValue('sharedSecret', '');
		if ($secret === '') {
			throw new StartLinkUnavailableException(StartLinkUnavailableException::REASON_SHARED_SECRET);
		}

		$ts = (string) $this->timeFactory->getTime();
		$nonce = Base64Url::encode($this->nonceSource->bytes(self::NONCE_BYTES));

		// String to sign: the user id goes in raw here but percent-encoded in
		// the link; ts and nonce are the same text in both places.
		$toSign = implode("\n", [$provider, $userId, $ts, $nonce]);
		$sig = Base64Url::encode(hash_hmac('sha256', $toSign, $secret, true));

		return $baseUrl . '/auth/' . $provider . '/start'
			. '?user=' . rawurlencode($userId)
			. '&ts=' . $ts
			. '&nonce=' . $nonce
			. '&sig=' . $sig;
	}
}
