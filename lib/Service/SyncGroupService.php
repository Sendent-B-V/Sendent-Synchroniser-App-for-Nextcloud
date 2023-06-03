<?php

namespace OCA\SendentSynchroniser\Service;

use Exception;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

use OCA\SendentSynchroniser\Db\SyncGroup;
use OCA\SendentSynchroniser\Db\SyncGroupMapper;

class SyncGroupService {
	private $mapper;

	public function __construct(SyncGroupMapper $mapper) {
		$this->mapper = $mapper;
	}

	public function findAll() {
		return $this->mapper->findAll();
	}

	/**
	 * @return never
	 */
	private function handleException(Exception $e) {
		if ($e instanceof DoesNotExistException ||
			$e instanceof MultipleObjectsReturnedException) {
			throw new NotFoundException($e->getMessage());
		} else {
			throw $e;
		}
	}

	public function findByName(string $name) {
		return $this->mapper->findByName($name);
	}

	public function find(int $id) {
		try {
			return $this->mapper->find($id);

			// in order to be able to plug in different storage backends like files
		// for instance it is a good idea to turn storage related exceptions
		// into service related exceptions so controllers and service users
		// have to deal with only one type of exception
		} catch (Exception $e) {
			$this->handleException($e);
		}
	}
	
	public function create(string $name): \OCP\AppFramework\Db\Entity {
		$syncGroup = new SyncGroup();
		$syncGroup->setName($name);
		return $this->mapper->insert($syncGroup);
	}

	public function updateSyncGroupList($newSendentGroups){
		$sendentGroups = $this->mapper->findAll();
		foreach ($sendentGroups as $groupToDelete) {
			$this->mapper->delete($groupToDelete);
		}
		//error_log($newGroups);

		foreach($newSendentGroups as $newGroup)
		{
			$syncGroup = new SyncGroup();
			$syncGroup->setName($newGroup);
			$this->mapper->insert($syncGroup);
		}
		return $this->mapper->findAll();
	}
	public function update(int $id, string $name): \OCP\AppFramework\Db\Entity {
		try {
			$syncGroup = $this->mapper->find($id);
		} catch (Exception $e) {
			$this->handleException($e);
		}
		$syncGroup->setName($name);
		return $this->mapper->update($syncGroup);
	}

	public function destroy(int $id): \OCP\AppFramework\Db\Entity {
		try {
			$syncGroup = $this->mapper->findById($id);
		} catch (Exception $e) {
			$this->handleException($e);
		}
		$this->mapper->delete($syncGroup);
		return $syncGroup;
	}
}
