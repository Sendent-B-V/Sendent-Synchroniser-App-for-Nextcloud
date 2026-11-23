<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\ChangeNotification;

/**
 * What happened to one event file, as the per-event feed reports it. The
 * backing values are the wire values.
 *
 * There is no "moved", "trashed" or "restored": the Connector only needs to
 * know whether to fetch the file or to drop it.
 */
enum EventChangeKind: string {
	case Created = 'created';
	case Updated = 'updated';
	case Deleted = 'deleted';
}
