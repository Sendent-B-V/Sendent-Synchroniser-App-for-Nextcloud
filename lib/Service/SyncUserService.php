<?php

namespace OCA\SendentSynchroniser\Service;

use Exception;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

use OCA\SendentSynchroniser\Db\SyncUser;
use OCA\SendentSynchroniser\Db\SyncUserMapper;

class SyncUserService {
	private $mapper;

	public function __construct(SyncUserMapper $mapper) {
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

	public function findByUsername(string $username) {
		return $this->mapper->findByUsername($username);
	}
	public function findByGroupId(string $groupId) {
		return $this->mapper->findByGroupId($groupId);
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

	public function create(string $username, string $groupId): \OCP\AppFramework\Db\Entity {
		$syncUser = new SyncUser();
		$syncUser->setUsername($username);
		$syncUser->setGroupId($groupId);
		return $this->mapper->insert($syncUser);
	}

	public function update(int $id, string $username, string $groupId): \OCP\AppFramework\Db\Entity {
		try {
			$syncUser = $this->mapper->find($id);
		} catch (Exception $e) {
			$this->handleException($e);
		}
		$syncUser->setUsername($username);
		$syncUser->setGroupId($groupId);
		return $this->mapper->update($syncUser);
	}

	public function destroy(int $id): \OCP\AppFramework\Db\Entity {
		try {
			$syncUser = $this->mapper->findById($id);
		} catch (Exception $e) {
			$this->handleException($e);
		}
		$this->mapper->delete($syncUser);
		return $syncUser;
	}
}
