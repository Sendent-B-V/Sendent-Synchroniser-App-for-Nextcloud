<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One row of sndntsync_authstatus: the sign-in status of one user with one
 * provider, as last reported by the sync service through the status callback.
 *
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getProvider()
 * @method void setProvider(string $provider)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method ?string getReason()
 * @method void setReason(?string $reason)
 * @method int getChangedAt()
 * @method void setChangedAt(int $changedAt)
 */
class AuthStatus extends Entity implements JsonSerializable {
	protected $uid;
	protected $provider;
	protected $status;
	protected $reason;
	protected $changedAt;

	public function __construct() {
		$this->addType('changedAt', 'integer');
	}

	/**
	 * Shape returned to the logged-in user's own browser (never to the service).
	 */
	public function jsonSerialize(): array {
		return [
			'provider' => $this->getProvider(),
			'status' => $this->getStatus(),
			'reason' => $this->getReason(),
			'changedAt' => $this->getChangedAt(),
		];
	}
}
