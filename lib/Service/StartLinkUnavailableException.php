<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service;

/**
 * Thrown by StartLinkBuilder when no link can be built. The reason is one of
 * the three constants below; the controller echoes it to the caller so an
 * admin can see which setting is missing. These reasons are Nextcloud-side
 * only and deliberately separate from the `msauth=failed` reasons the sync
 * service sends back when it rejects a link.
 */
final class StartLinkUnavailableException extends ServiceException {

	/** Sign-in mode is serviceAccount, there is nothing to connect. */
	public const REASON_SERVICE_ACCOUNT = 'serviceAccount';
	/** Constants::SYNC_SERVICE_PUBLIC_URL_KEY is empty. */
	public const REASON_PUBLIC_URL = 'publicUrl';
	/** The sharedSecret app value is empty. */
	public const REASON_SHARED_SECRET = 'sharedSecret';

	public function __construct(
		private readonly string $reason,
	) {
		parent::__construct('Microsoft sign-in start link unavailable: ' . $reason);
	}

	public function getReason(): string {
		return $this->reason;
	}
}
