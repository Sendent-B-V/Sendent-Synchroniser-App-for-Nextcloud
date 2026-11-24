<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\ChangeNotification;

/**
 * One per-event row that was written: the change and the number it got.
 * Two rows never share a stamp, also not two rows for the same event.
 */
final class EventStamp {

	public function __construct(
		public readonly EventChange $change,
		/** The sequence number of the row. */
		public readonly int $seq,
	) {}
}
