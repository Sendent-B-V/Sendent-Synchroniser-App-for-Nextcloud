<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Db;

use OCA\SendentSynchroniser\ChangeNotification\CollectionReference;
use OCP\AppFramework\Db\Entity;

/**
 * One row of the change ledger: the current dirty state of a single DAV
 * collection. Upserted, never appended, so the table is bounded by the number
 * of collections on the instance.
 *
 * @method string getPrincipalUri()
 * @method void setPrincipalUri(string $principalUri)
 * @method string getCollectionType()
 * @method void setCollectionType(string $collectionType)
 * @method string getCollectionUri()
 * @method void setCollectionUri(string $collectionUri)
 * @method int getSyncToken()
 * @method void setSyncToken(int $syncToken)
 * @method int getChangeSeq()
 * @method void setChangeSeq(int $changeSeq)
 * @method int getStructuralSeq()
 * @method void setStructuralSeq(int $structuralSeq)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class DirtyCollection extends Entity {

	protected $principalUri;
	protected $collectionType;
	protected $collectionUri;
	protected $syncToken;
	protected $changeSeq;
	protected $structuralSeq;
	protected $updatedAt;

	public function __construct() {
		$this->addType('syncToken', 'integer');
		$this->addType('changeSeq', 'integer');
		$this->addType('structuralSeq', 'integer');
		$this->addType('updatedAt', 'integer');
	}

	/**
	 * Converts the row to a wire reference relative to a reader's position.
	 *
	 * `collectionChanged` is computed, not stored: it is true exactly when the
	 * last structural change happened after the sequence number the reader has
	 * already seen. That keeps the flag correct on a re-read, which matters
	 * because readers deliberately overlap (see the plan's deviation 5).
	 */
	public function toReference(int $since): CollectionReference {
		return new CollectionReference(
			(string)$this->getPrincipalUri(),
			(string)$this->getCollectionType(),
			(string)$this->getCollectionUri(),
			(int)$this->getSyncToken(),
			(int)$this->getStructuralSeq() > $since
		);
	}
}
