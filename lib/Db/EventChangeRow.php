<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One row of the per-event change table: one change to one event file.
 * Appended, never upserted. Holds no event content. Holds the UID of the
 * event when it was known at the time of the write, else null.
 *
 * @method string getPrincipalUri()
 * @method void setPrincipalUri(string $principalUri)
 * @method string getCalendarUri()
 * @method void setCalendarUri(string $calendarUri)
 * @method string getEventFileName()
 * @method void setEventFileName(string $eventFileName)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method int getSequenceNumber()
 * @method void setSequenceNumber(int $sequenceNumber)
 * @method int getWrittenTime()
 * @method void setWrittenTime(int $writtenTime)
 * @method string|null getEventUid()
 * @method void setEventUid(?string $eventUid)
 */
class EventChangeRow extends Entity {

	protected $principalUri;
	protected $calendarUri;
	protected $eventFileName;
	protected $kind;
	protected $sequenceNumber;
	protected $writtenTime;
	protected $eventUid;

	public function __construct() {
		$this->addType('sequenceNumber', 'integer');
		$this->addType('writtenTime', 'integer');
	}
}
