<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

/**
 * HMAC-SHA256 signing for the optional outbound webhook. The canonical string
 * binds timestamp and nonce to the raw body, so a captured request can be
 * neither replayed later (receiver enforces a timestamp window + nonce cache)
 * nor rebound to a different payload.
 *
 * Header: X-Sendent-Signature: t=<unix>,n=<nonce>,s=<hex hmac>
 */
class WebhookSigner {

	public const HEADER = 'X-Sendent-Signature';

	public function sign(string $secret, int $timestamp, string $nonce, string $body): string {
		return hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $secret);
	}

	public function verify(string $secret, int $timestamp, string $nonce, string $body, string $signature): bool {
		return hash_equals($this->sign($secret, $timestamp, $nonce, $body), $signature);
	}

	public function headerValue(int $timestamp, string $nonce, string $signature): string {
		return 't=' . $timestamp . ',n=' . $nonce . ',s=' . $signature;
	}
}
