<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One row of the per-event change table: one change to one event file.
 * Appended, never upserted. Holds no event content.
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
 */
class EventChangeRow extends Entity {

	protected $principalUri;
	protected $calendarUri;
	protected $eventFileName;
	protected $kind;
	protected $sequenceNumber;
	protected $writtenTime;

	public function __construct() {
		$this->addType('sequenceNumber', 'integer');
		$this->addType('writtenTime', 'integer');
	}
}
