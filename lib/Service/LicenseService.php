<?php

namespace OCA\SendentSynchroniser\Service;

use DateTime;
use Exception;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IUserManager;
use \OCP\ILogger;
use OCA\SendentSynchroniser\Db\License;
use OCA\SendentSynchroniser\Db\LicenseMapper;
use OCA\SendentSynchroniser\Service\SendentFileStorageManager;

class LicenseService {

	private $appConfig;
	private $mapper;
	private $FileStorageManager;

	/** @var ILogger */
	private $logger;

	public function __construct(IAppConfig $appConfig, ILogger $logger,
				LicenseMapper $mapper, SendentFileStorageManager $FileStorageManager) {

		$this->appConfig = $appConfig;
		$this->mapper = $mapper;
		$this->FileStorageManager = $FileStorageManager;
		$this->logger = $logger;

	}

	public function delete() {
		try {
			$list = $this->mapper->findAll();
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
	string $email, DateTime $datelastchecked, string $level) {
		error_log(print_r("LICENSESERVICE-CREATE", true));

		try {
			error_log(print_r("LICENSESERVICE-LEVEL=		" . $level, true));

			return $this->update(0, $license,
			$dategraceperiodend, $datelicenseend,
			$maxusers, $maxgraceusers, $email, $datelastchecked, $level);
		} catch (Exception $e) {
			error_log(print_r("LICENSESERVICE-EXCEPTION=" . $e, true));

			$licenseobj = new License();
			
			$value = $this->FileStorageManager->writeLicenseTxt($license);
			$licenseobj->setLicensekey($value);
			$licenseobj->setEmail($email);
			$licenseobj->setLevel($level);
			$licenseobj->setMaxusers($maxusers);
			$licenseobj->setMaxgraceusers($maxgraceusers);
			$licenseobj->setDategraceperiodend(date_format($dategraceperiodend, "Y-m-d"));
			$licenseobj->setDatelicenseend(date_format($datelicenseend, "Y-m-d"));
			$licenseobj->setDatelastchecked(date_format($datelastchecked, "Y-m-d"));

			error_log(print_r("LICENSESERVICE-EXCEPTION-LEVEL=" . $licenseobj->getLevel(), true));
			$licenseresult = $this->mapper->insert($licenseobj);
			if ($this->valueIsLicenseKeyFilePath($licenseresult->getLicensekey()) !== false) {
				$licenseresult->setLicensekey($this->FileStorageManager->getLicenseContent());
			}

			return $licenseresult;
		}
	}

	public function createNew(string $license, string $email): \OCP\AppFramework\Db\Entity {
		$licenseobj = new License;
		
		$value = $this->FileStorageManager->writeLicenseTxt($license);
		$licenseobj->setLicensekey($value);
		$licenseobj->setEmail($email);
		$licenseobj->setLevel("None");
		$licenseobj->setMaxusers(1);
		$licenseobj->setMaxgraceusers(1);
		$licenseobj->setDategraceperiodend(date_format(date_create("now"), "Y-m-d"));
		$licenseobj->setDatelicenseend(date_format(date_create("now"), "Y-m-d"));
		$licenseobj->setDatelastchecked(date_format(date_create("now"), "Y-m-d"));

		$licenseresult = $this->mapper->insert($licenseobj);
		if ($this->valueIsLicenseKeyFilePath($licenseresult->getLicensekey()) !== false) {
			$licenseresult->setLicensekey($this->FileStorageManager->getLicenseContent());
		}
		return $licenseresult;
	}

	public function update(int $id,string $license, DateTime $dategraceperiodend,
	DateTime $datelicenseend, int $maxusers, int $maxgraceusers,
	string $email, DateTime $datelastchecked, string $level): \OCP\AppFramework\Db\Entity {
		error_log(print_r("LICENSESERVICE-UPDATE", true));
		$licenseobj = new License();

		$value = $this->FileStorageManager->writeLicenseTxt($license);
		$licenseobj->setLicensekey($value);
		$licenseobj->setId($id);
		$licenseobj->setEmail($email);
		$licenseobj->setLevel($level);
		$licenseobj->setMaxusers($maxusers);
		$licenseobj->setMaxgraceusers($maxgraceusers);
		$licenseobj->setDategraceperiodend(date_format($dategraceperiodend, "Y-m-d"));
		$licenseobj->setDatelicenseend(date_format($datelicenseend, "Y-m-d"));
		$licenseobj->setDatelastchecked(date_format($datelastchecked, "Y-m-d"));
		
		$licenseresult = $this->mapper->update($licenseobj);
		if ($this->valueIsLicenseKeyFilePath($licenseresult->getLicensekey()) !== false) {
			$licenseresult->setLicensekey($this->FileStorageManager->getLicenseContent());
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
