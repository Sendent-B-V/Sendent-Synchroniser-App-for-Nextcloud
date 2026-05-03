<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Db;

use DateTimeInterface;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string|null getRoomId()
 * @method void setRoomId(string $roomId)
 * @method string|null getKind()
 * @method void setKind(string $kind)
 * @method string|null getExternalId()
 * @method void setExternalId(string $externalId)
 * @method string|null getConfig()
 * @method void setConfig(string $configJson)
 * @method int|null getLinkVersion()
 * @method void setLinkVersion(int $linkVersion)
 * @method string|null getState()
 * @method void setState(string $state)
 * @method DateTimeInterface|null getLastSyncedAt()
 * @method void setLastSyncedAt(?DateTimeInterface $lastSyncedAt)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method bool|null getInitialSyncRequested()
 * @method void setInitialSyncRequested(bool $requested)
 * @method int|null getLastEventsPushed()
 * @method void setLastEventsPushed(int $n)
 * @method int|null getLastEventsPulled()
 * @method void setLastEventsPulled(int $n)
 * @method DateTimeInterface|null getCreatedAt()
 * @method void setCreatedAt(DateTimeInterface $t)
 * @method DateTimeInterface|null getUpdatedAt()
 * @method void setUpdatedAt(DateTimeInterface $t)
 */
class RoomBinding extends Entity implements JsonSerializable {
	public const STATE_PENDING = 'pending';
	public const STATE_SYNCING = 'syncing';
	public const STATE_COMPLETED = 'completed';
	public const STATE_FAILED = 'failed';
	public const STATE_IDLE = 'idle';

	protected $roomId;
	protected $kind;
	protected $externalId;
	protected $config;
	protected $linkVersion;
	protected $state;
	protected $lastSyncedAt;
	protected $lastError;
	protected $initialSyncRequested;
	protected $lastEventsPushed;
	protected $lastEventsPulled;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		// roomId is the (string) PK on this table — NC doesn't auto-cast it
		// (the integer-cast default only applies to a field literally named `id`),
		// but pin it explicitly so a future refactor doesn't accidentally regress.
		$this->addType('roomId', 'string');
		$this->addType('linkVersion', 'integer');
		$this->addType('initialSyncRequested', 'boolean');
		$this->addType('lastEventsPushed', 'integer');
		$this->addType('lastEventsPulled', 'integer');
		$this->addType('lastSyncedAt', 'datetime');
		$this->addType('createdAt', 'datetime');
		$this->addType('updatedAt', 'datetime');
	}

	public function getConfigArray(): array {
		if ($this->config === null || $this->config === '') {
			return [];
		}
		$decoded = json_decode($this->config, true);
		return is_array($decoded) ? $decoded : [];
	}

	public function jsonSerialize(): array {
		return [
			'roomId' => $this->roomId,
			'kind' => $this->kind,
			'externalId' => $this->externalId,
			'linkVersion' => $this->linkVersion,
			'state' => $this->state,
			'lastSyncedAt' => $this->lastSyncedAt?->format(\DateTimeInterface::ATOM),
			'lastError' => $this->lastError,
			'initialSyncRequested' => (bool) $this->initialSyncRequested,
			'stats' => [
				'eventsPushed' => (int) $this->lastEventsPushed,
				'eventsPulled' => (int) $this->lastEventsPulled,
			],
		];
	}
}
