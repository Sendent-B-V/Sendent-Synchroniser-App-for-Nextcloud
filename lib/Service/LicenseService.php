<?php

namespace OCA\SendentSynchroniser\Service;

use DateTime;
use Exception;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

use OCA\SendentSynchroniser\Db\License;
use OCA\SendentSynchroniser\Db\LicenseMapper;
use OCA\SendentSynchroniser\Service\SendentFileStorageManager;

class LicenseService {
	private $appConfig;
	private $groupManager;
	private $mapper;
	private $FileStorageManager;
	private $userManager;
	/** @var LoggerInterface */
	private $logger;

	public function __construct(IAppConfig $appConfig, IGroupManager $groupManager, LoggerInterface $logger,
				LicenseMapper $mapper, SendentFileStorageManager $FileStorageManager, IUserManager $userManager) {
		$this->appConfig = $appConfig;
		$this->groupManager = $groupManager;
		$this->mapper = $mapper;
		$this->FileStorageManager = $FileStorageManager;
		$this->userManager = $userManager;
		$this->logger = $logger;
	}

	public function delete(string $ncgroup = '') {
		try {
			$list = $this->mapper->findByGroup($ncgroup);
			foreach ($list as $result) {
				$this->mapper->delete($result);
			}
		} catch (Exception $e) {
			$this->handleException($e);
		}
	}

	public function findAll() {
		try {
			$list = $this->mapper->findAll();
			foreach ($list as $result) {
				if ($this->valueIsLicenseKeyFilePath($result->getLicensekey()) !== false) {
					$result->setLicensekey($this->FileStorageManager->getLicenseContent());
				}
			}
			return $list;
			// in order to be able to plug in different storage backends like files
		// for instance it is a good idea to turn storage related exceptions
		// into service related exceptions so controllers and service users
		// have to deal with only one type of exception
		} catch (Exception $e) {
			$this->handleException($e);
		}
	}

	public function findByGroup(string $ncgroup = '') {
		try {
			$list = $this->mapper->findByGroup($ncgroup);
			foreach ($list as $result) {
				if ($this->valueIsLicenseKeyFilePath($result->getLicensekey()) !== false) {
					$result->setLicensekey($this->FileStorageManager->getLicenseContent($ncgroup));
				}
			}
			return $list;
			// in order to be able to plug in different storage backends like files
		// for instance it is a good idea to turn storage related exceptions
		// into service related exceptions so controllers and service users
		// have to deal with only one type of exception
		} catch (Exception $e) {
			$this->handleException($e);
		}
	}

	public function find(int $id): void {
		try {
			$licensekey = $this->mapper->find($id);
			if ($this->valueIsLicenseKeyFilePath($licensekey->getLicensekey()) !== false) {
				$licensekey->setLicensekey($this->FileStorageManager->getLicenseContent());
			}

			// in order to be able to plug in different storage backends like files
		// for instance it is a good idea to turn storage related exceptions
		// into service related exceptions so controllers and service users
		// have to deal with only one type of exception
		} catch (Exception $e) {
			$this->handleException($e);
		}
	}

	public function findByLicenseKey(string $key): void {
		try {
			$licensekey = $this->mapper->findByLicenseKey($key);
			if ($this->valueIsLicenseKeyFilePath($licensekey->getLicensekey()) !== false) {
				$licensekey->setLicensekey($this->FileStorageManager->getLicenseContent());
			}
			// in order to be able to plug in different storage backends like files
		// for instance it is a good idea to turn storage related exceptions
		// into service related exceptions so controllers and service users
		// have to deal with only one type of exception
		} catch (Exception $e) {
			$this->handleException($e);
		}
	}

	/**
	 * Finds the license used by a user
	 */
	public function findUserLicense(string $userId) {
		// Gets groups for which specific settings and/or license are defined
		// Groups are ordered from highest priority to lowest
		$sendentGroups = $this->appConfig->getAppValue('sendentGroups', '');
		$sendentGroups = $sendentGroups !== '' ? json_decode($sendentGroups) : [];
		$this->logger->debug('Found sendent groups: ' . implode(',', $sendentGroups));

		// Gets user groups
		$user = $this->userManager->get($userId);
		$userGroups = $this->groupManager->getUserGroups($user);
		$userGroups = array_map(function ($group) {
			return $group->getGid();
		}, $userGroups);
		$this->logger->debug('Found user groups: ' . implode(',', $userGroups));

		// Gets user groups that are sendentGroups
		$userSendentGroups = array_intersect($sendentGroups, $userGroups);
		$this->logger->debug('Groups intersection: ' . implode(',', $userSendentGroups));

		// Finds user license
		if (count($userSendentGroups) === 0) {
			// User is not member of any sendentGroups => Gets default license
			$this->logger->debug('User is not member of any sendent group, getting default group license');
			$license = $this->findByGroup('');
		} else {
			// Gets license of first matching group (highest priority)
			$license = $this->findByGroup($userSendentGroups[array_keys($userSendentGroups)[0]]);
			// If the group has no license assigned, then gets default license
			if (count($license) === 0 || $license[0]->getLicensekey() === '') {
				$this->logger->debug('Did not find a license for group ' . $userSendentGroups[array_keys($userSendentGroups)[0]]);
				$license = $this->findByGroup('');
			}
			$this->logger->debug('Found license: ' . $license[0]->getId() . ' for group ' . $userSendentGroups[array_keys($userSendentGroups)[0]]);
		}

		// If we haven't found a license, then usage is unlicensed
		if (count($license) === 0) {
			return null;
		} else {
			$this->logger->debug('Found license: ' . $license[0]->getId());
			return $license[0];
		}
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

	public function create(string $license, DateTime $dategraceperiodend,
	DateTime $datelicenseend, int $maxusers, int $maxgraceusers,
	string $email, DateTime $datelastchecked, string $level, string $ncgroup = '') {
		error_log(print_r("LICENSESERVICE-CREATE", true));

		try {
			error_log(print_r("LICENSESERVICE-LEVEL=		" . $level, true));

			return $this->update(0, $license,
			$dategraceperiodend, $datelicenseend,
			$maxusers, $maxgraceusers, $email, $datelastchecked, $level, $ncgroup);
		} catch (Exception $e) {
			error_log(print_r("LICENSESERVICE-EXCEPTION=" . $e, true));

			$licenseobj = new License();
			
			$value = $this->FileStorageManager->writeLicenseTxt($license, $ncgroup);
			$licenseobj->setLicensekey($value);
			$licenseobj->setEmail($email);
			$licenseobj->setLevel($level);
			$licenseobj->setMaxusers($maxusers);
			$licenseobj->setMaxgraceusers($maxgraceusers);
			$licenseobj->setDategraceperiodend(date_format($dategraceperiodend, "Y-m-d"));
			$licenseobj->setDatelicenseend(date_format($datelicenseend, "Y-m-d"));
			$licenseobj->setDatelastchecked(date_format($datelastchecked, "Y-m-d"));
			$licenseobj->setNcgroup($ncgroup);

			error_log(print_r("LICENSESERVICE-EXCEPTION-LEVEL=" . $licenseobj->getLevel(), true));
			$licenseresult = $this->mapper->insert($licenseobj);
			if ($this->valueIsLicenseKeyFilePath($licenseresult->getLicensekey()) !== false) {
				$licenseresult->setLicensekey($this->FileStorageManager->getLicenseContent());
			}

			return $licenseresult;
		}
	}

	public function createNew(string $license, string $email, string $ncgroup = ''): \OCP\AppFramework\Db\Entity {
		$licenseobj = new License();
		
		$value = $this->FileStorageManager->writeLicenseTxt($license, $ncgroup);
		$licenseobj->setLicensekey($value);
		$licenseobj->setEmail($email);
		$licenseobj->setLevel("None");
		$licenseobj->setMaxusers(1);
		$licenseobj->setMaxgraceusers(1);
		$licenseobj->setDategraceperiodend(date_format(date_create("now"), "Y-m-d"));
		$licenseobj->setDatelicenseend(date_format(date_create("now"), "Y-m-d"));
		$licenseobj->setDatelastchecked(date_format(date_create("now"), "Y-m-d"));
		$licenseobj->setNcgroup($ncgroup);

		$licenseresult = $this->mapper->insert($licenseobj);
		if ($this->valueIsLicenseKeyFilePath($licenseresult->getLicensekey()) !== false) {
			$licenseresult->setLicensekey($this->FileStorageManager->getLicenseContent($ncgroup));
		}
		return $licenseresult;
	}

	public function update(int $id,string $license, DateTime $dategraceperiodend,
	DateTime $datelicenseend, int $maxusers, int $maxgraceusers,
	string $email, DateTime $datelastchecked, string $level, string $ncgroup = ''): \OCP\AppFramework\Db\Entity {
		error_log(print_r("LICENSESERVICE-UPDATE", true));
		$licenseobj = new License();

		$value = $this->FileStorageManager->writeLicenseTxt($license, $ncgroup);
		$licenseobj->setLicensekey($value);
		$licenseobj->setId($id);
		$licenseobj->setEmail($email);
		$licenseobj->setLevel($level);
		$licenseobj->setMaxusers($maxusers);
		$licenseobj->setMaxgraceusers($maxgraceusers);
		$licenseobj->setDategraceperiodend(date_format($dategraceperiodend, "Y-m-d"));
		$licenseobj->setDatelicenseend(date_format($datelicenseend, "Y-m-d"));
		$licenseobj->setDatelastchecked(date_format($datelastchecked, "Y-m-d"));
		$licenseobj->setNcgroup($ncgroup);
		
		$licenseresult = $this->mapper->update($licenseobj);
		if ($this->valueIsLicenseKeyFilePath($licenseresult->getLicensekey()) !== false) {
			$licenseresult->setLicensekey($this->FileStorageManager->getLicenseContent($ncgroup));
		}
		return $licenseresult;
	}

	public function destroy(int $id): \OCP\AppFramework\Db\Entity {
		try {
			$license = $this->mapper->find($id);
		} catch (Exception $e) {
			$this->handleException($e);
		}
		$this->mapper->delete($license);
		return $license;
	}

	private function cleanupLicenses(string $licenseToKeep): void {
		$licenses = $this->mapper->findAll();
		if (isset($licenses)) {
			foreach ($licenses as $license) {
				$this->destroy($license->getId());
			}
		}
	}
	private function valueIsLicenseKeyFilePath($value): bool {
		if (strpos($value, 'licenseKeyFile') !== false) {
			return true;
		}
		return false;
	}

	private function valueSizeForDb($value): bool {
		return strlen($value) > 254;
	}
}
