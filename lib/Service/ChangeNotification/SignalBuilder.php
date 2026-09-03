<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCA\SendentSynchroniser\Constants;
use OCP\IConfig;

/**
 * Assembles the one wire payload used by the websocket body, the /changes
 * response and the optional webhook. Keeping a single builder is what
 * guarantees the Connector can treat all three identically.
 */
class SignalBuilder {

	public function __construct(
		private IConfig $config,
	) {}

	/**
	 * @param int $prev the feed position BEFORE this signal (the watermark the
	 *                  flush started from). Sequence numbers count events while
	 *                  refs are deduped per collection, so `cursor - len(refs)`
	 *                  tells a reader nothing; `prev` makes gap detection exact:
	 *                  a reader whose last_cursor < prev has missed a frame.
	 * @param CollectionReference[] $refs
	 * @return array{v: int, instance: string, prev: int, cursor: int, truncated: bool, refs: array<int, array{p: string, t: string, u: string, s: int, c: bool}>}
	 */
	public function build(int $prev, int $cursor, array $refs, bool $truncated): array {
		return [
			'v' => Constants::CN_SIGNAL_VERSION,
			'instance' => $this->config->getSystemValueString('instanceid'),
			'prev' => $prev,
			'cursor' => $cursor,
			// Truncated signals carry no refs: cheaper for the Connector to page /changes than parse a giant frame.
			'truncated' => $truncated,
			'refs' => $truncated
				? []
				: array_map(static fn (CollectionReference $r): array => $r->jsonSerialize(), $refs),
		];
	}
}
