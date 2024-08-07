<?php

namespace OCA\SendentSynchroniser\Service;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use Psr\Log\LoggerInterface;

use OCA\SendentSynchroniser\Db\License;
use OCA\SendentSynchroniser\Http\SubscriptionValidationHttpClient;
use OCA\SendentSynchroniser\Service\LicenseService;
use OCA\SendentSynchroniser\Db\SyncUser;
use OCA\SendentSynchroniser\Db\SyncUserMapper;
use OCA\SendentSynchroniser\Service\SyncUserService;

use Exception;

class LicenseManager
{
	protected $licenseservice;
	protected $subscriptionvalidationhttpclient;

	/** @var LoggerInterface */
	private $logger;

	/** @var SyncUserMapper */
	private $syncUserMapper;

	/** @var SyncUserService */
	private $syncUserService;

	public function __construct(
		LicenseService $licenseservice,
		LoggerInterface $logger,
		SubscriptionValidationHttpClient $subscriptionvalidationhttpclient,
		SyncUserMapper $syncUserMapper,
		SyncUserService $syncUserService
	) {
		$this->licenseservice = $licenseservice;
		$this->logger = $logger;
		$this->subscriptionvalidationhttpclient = $subscriptionvalidationhttpclient;
		$this->syncUserMapper = $syncUserMapper;
		$this->syncUserService = $syncUserService;
	}

	/**
	 * @return never
	 */
	private function handleException(Exception $e)
	{
		if (
			$e instanceof DoesNotExistException ||
			$e instanceof MultipleObjectsReturnedException
		) {
			throw new NotFoundException($e->getMessage());
		} else {
			throw $e;
		}
	}

	/**
	 *
	 * Reports licenses usage to sendent licensing server
	 *
	 */
	public function pingLicensing(License $license): void
	{
		try {
			if (str_contains($license->getEmail(), 'OFFLINE_')) {
				$this->logger->info('NOT pinging licensing server because of offline support with license ' . $license->getId());
			} else {
				error_log(print_r('Pinging licensing server with license ' . $license->getId(), true));
				$this->logger->info('Pinging licensing server with license ' . $license->getId());
				$pingResultLicense = $this->subscriptionvalidationhttpclient->validate($license);
			}
		} catch (Exception $e) {
			$this->logger->error('Error while pinging licensing server');
		}
	}

	public function renewLicense(License $license)
	{
		$this->logger->info('Renewing license ' . $license->getId());
		error_log(print_r("Renewing license " . $license->getId(), true));
		if (str_contains($license->getEmail(), 'OFFLINE_')) {
			$this->logger->info('NOT pinging licensing server because of offline support with license ' . $license->getId());
			return $this->licenseservice->update(
				$license->getId(),
				$license->getLicensekey(),
				$license->getLicensekey(),
				License::OFFLINE_MODE,
				date_create("+1 day"),
				date_create("+1 day"),
				1,
				1,
				$license->getEmail(),
				date_create("now"),
				License::OFFLINE_MODE,
				License::OFFLINE_MODE,
				"Outlook",
				-1,
				$license->getNcgroup()
			);
		} else {
			$license = $this->subscriptionvalidationhttpclient->validate($license);
			if (isset($license) && $license != null) {
				$maxUsers = $license->getMaxusers();
				if (!isset($maxUsers)) {
					$maxUsers = 1;
				}
				$maxGraceUsers = $license->getMaxgraceusers();
				if (!isset($maxGraceUsers)) {
					$maxGraceUsers = 1;
				}
				$level = $license->getLevel();

				if ($level != License::ERROR_VALIDATING) {
					error_log(print_r("RENEWLICENSE LICENSE LEVEL IS NOT ERROR_VALIDATING", true));

					return $this->licenseservice->update(
						$license->getId(),
						$license->getLicensekey(),
						$license->getLicensekeytoken(),
						$license->getSubscriptionstatus(),
						date_create($license->getDategraceperiodend()),
						date_create($license->getDatelicenseend()),
						$maxUsers,
						$maxGraceUsers,
						$license->getEmail(),
						date_create($license->getDatelastchecked()),
						$level,
						$license->getTechnicallevel(),
						$license->getProduct(),
						$license->getIstrial()
					);
				}
			} else {
				$license = new License();
				$license->setLevel("nolicense");
				return $license;
			}
		}
	}

	public function createLicense(string $license, string $licenseKeyToken, string $subscriptionStatus, string $email)
	{
		$this->logger->info('Creating license');
		$this->deleteLicense();
		$licenseData = $this->licenseservice->createNew($license, $licenseKeyToken, $subscriptionStatus, $email);
		return $this->activateLicense($licenseData);
	}

	public function deleteLicense()
	{
		try {
			$this->logger->info('Deleting license for default group');
			$this->licenseservice->delete();
		} catch (Exception $e) {
			$this->logger->error('Error while deleting license');
		}
	}

	public function activateLicense(License $license)
	{
		error_log(print_r("LICENSEMANAGER-ACTIVATELICENSE", true));
		if (str_contains($license->getEmail(), 'OFFLINE_')) {
			$this->logger->info('Overriding licensekeytoken for offline support ' . $license->getId());
			return $license;
		} else {
			$activatedLicense = $this->subscriptionvalidationhttpclient->activate($license);
			if (isset($activatedLicense)) {
				$level = $activatedLicense->getLevel();
				error_log(print_r("LICENSEMANAGER-LEVEL=		" . $level, true));

				if (!isset($level) && ($activatedLicense->getEmail() == "" || $activatedLicense->getLicensekey() == "")) {
					$level = "Error_incomplete";
					error_log(print_r("LICENSEMANAGER-LEVEL=		Error_incomplete", true));
				} elseif (!isset($level)) {
					$level = License::ERROR_VALIDATING;
					error_log(print_r("LICENSEMANAGER-LEVEL=		" . License::ERROR_VALIDATING, true));
				}
				$maxUsers = $activatedLicense->getMaxusers();
				if (!isset($maxUsers)) {
					$maxUsers = 1;
				}
				$maxGraceUsers = $activatedLicense->getMaxgraceusers();
				if (!isset($maxGraceUsers)) {
					$maxGraceUsers = 1;
				}
				error_log(print_r("LICENSEMANAGER-LEVEL=		" . $level, true));

				return $this->licenseservice->create(
					$activatedLicense->getLicensekey(),
					$activatedLicense->getLicensekeytoken(),
					$activatedLicense->getSubscriptionstatus(),
					date_create($activatedLicense->getDategraceperiodend()),
					date_create($activatedLicense->getDatelicenseend()),
					$maxUsers,
					$maxGraceUsers,
					$activatedLicense->getEmail(),
					date_create("now"),
					$level,
					$activatedLicense->getTechnicallevel(),
					$activatedLicense->getProduct(),
					$activatedLicense->getIstrial()
				);
			} else {
				$license = new License();
				$license->setLevel("nolicense");
				return $license;
			}
			return false;
		}
	}

	public function isLocalValid(License $license): bool
	{
		return !$license->isLicenseExpired() && ($this->isWithinUserCount($license) || $this->isWithinGraceUserCount($license)) && !$license->isCheckNeeded();
	}

	public function isWithinUserCount(License $license): bool
	{
		$userCount = $this->syncUserService->getValidUsers()->getCount();
		$maxUserCount = $license->getMaxusers();
		return $userCount <= $maxUserCount;
	}

	public function isWithinGraceUserCount(License $license): bool
	{
		$userCount = $this->syncUserService->getValidUsers()->getCount();
		$maxUserCount = $license->getMaxgraceusers();
		return $userCount <= $maxUserCount;
	}

	public function getCurrentUserCount()
	{
		return $this->syncUserService->getValidUsers()->getCount();
	}
}
