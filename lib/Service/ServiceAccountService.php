<?php

namespace OCA\SendentSynchroniser\Service;

use Exception;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

use OCA\SendentSynchroniser\Db\ServiceAccount;
use OCA\SendentSynchroniser\Db\ServiceAccountMapper;

class ServiceAccountService {
	private $mapper;

	public function __construct(ServiceAccountMapper $mapper) {
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

	public function create(string $username): \OCP\AppFramework\Db\Entity {
		$serviceAccount = new ServiceAccount();
		$serviceAccount->setUsername($username);
		return $this->mapper->insert($serviceAccount);
	}

	public function update(int $id, string $username): \OCP\AppFramework\Db\Entity {
		try {
			$serviceAccount = $this->mapper->find($id);
		} catch (Exception $e) {
			$this->handleException($e);
		}
		$serviceAccount->setUsername($username);
		return $this->mapper->update($serviceAccount);
	}

	public function destroy(int $id): \OCP\AppFramework\Db\Entity {
		try {
			$serviceAccount = $this->mapper->findById($id);
		} catch (Exception $e) {
			$this->handleException($e);
		}
		$this->mapper->delete($serviceAccount);
		return $serviceAccount;
	}
}
