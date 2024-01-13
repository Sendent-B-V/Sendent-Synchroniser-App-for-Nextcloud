<?php

namespace OCA\SendentSynchroniser\Controller;

use Exception;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Services\IAppConfig;
use OCA\SendentSynchroniser\Controller\Dto\LicenseStatus;
use OCA\SendentSynchroniser\Db\License;
use OCA\SendentSynchroniser\Service\ConnectedUserService;
use OCA\SendentSynchroniser\Service\LicenseManager;
use OCA\SendentSynchroniser\Service\LicenseService;
use OCA\SendentSynchroniser\Service\NotFoundException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class LicenseApiController extends ApiController {
	private $appConfig;
	private $licenseservice;
	private $licensemanager;

	/** @var IL10N */
	private $l;

	/** @var LoggerInterface */
	private $logger;

	public function __construct(
			  $appName,
			  IAppConfig $appConfig,
			  IRequest $request,
			  LicenseManager $licensemanager,
			  LicenseService $licenseservice,
			  LoggerInterface $logger,
			  IL10N $l,
	   ) {
		parent::__construct($appName, $request);
		$this->appConfig = $appConfig;
		$this->licenseservice = $licenseservice;
		$this->licensemanager = $licensemanager;
		$this->logger = $logger;
		$this->l = $l;
	}
	/**
	 * @return never
	 */
	private function handleException($e) {
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
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * Returns license status for group $ncgroup
	 *
	 * @return DataResponse
	 */
	public function show(): DataResponse {

		$this->logger->info('Getting license information for default group');

		try {
			// Gets license for group $ncgroup
			$result = $this->licenseservice->findAll();
			
			if (isset($result) && $result !== null && $result !== false) {
				if (is_array($result) && count($result) > 0
				&& $result[0]->getLevel() != "Error_clear" && $result[0]->getLevel() != "Error_incomplete") {


					$this->logger->info('Check needed for license ' . $result[0]->getId());
					try {
						$this->licensemanager->renewLicense($result[0]);
						$result = $this->licenseservice->findAll();
						if (isset($result) && $result !== null && $result !== false) {
							if (is_array($result) && count($result) > 0
							&& $result[0]->getLevel() != "Error_clear" && $result[0]->getLevel() != "Error_incomplete") {
							} else {
								//throw new Exception();
							}
						}
					} catch (Exception $e) {
						$this->logger->error('Error while renewing license ' . $result[0]->getId() . '\r\n' . $e);
					}

					// Reports license status
					$email = $result[0]->getEmail();
					$licensekey = $result[0]->getLicensekeytoken();
					$this->logger->info('License key returned is: ' . $licensekey);
					$dateExpiration = $result[0]->getDatelicenseend();
					$dateLastCheck = $result[0]->getDatelastchecked();
					$level = $result[0]->getLevel();
					$product = $result[0]->getProduct();
					$statusKind = "";
					$status = "";

					if ($result[0]->isCleared()) {
						$status = $this->l->t("No license configured");
						$statusKind = "nolicense";
					} 
					elseif ($result[0]->isIncomplete()) {
						$status = $this->l->t("Missing email address or license key.");
						$statusKind = "error_incomplete";
					} 
					elseif ($result[0]->isCheckNeeded()) {
						$status = $this->l->t("Revalidation of your license is required");
						$statusKind = "check";
					}
					elseif (!$result[0]->isCheckNeeded() && !$result[0]->isLicenseExpired() && !$result[0]->isSupportedProduct()) {
						$status = $this->l->t("Current license is not intended to be used with Sendent Synchroniser. It is only intended to be used for configuring: " . $product) .
							"</br>" .
							$this->l->t('%1$sContact support%2$s if you experience issues with configuring your license key.', ["<a href='mailto:info@sendent.com' style='color:blue'>", "</a>"]);
						$statusKind = "expired";
					} 
					elseif ($result[0]->isLicenseRenewedOrSwitched()) {
						$status = $this->l->t("Current license has been changed.") .
							"</br>" .
							$this->l->t('%1$sContact support%2$s if you experience issues with configuring your license key.', ["<a href='mailto:info@sendent.com' style='color:blue'>", "</a>"]);
						$statusKind = "check";
					}
					elseif ($result[0]->isLicenseInactive()) {
						$status = $this->l->t("Current license has been deactivated by Sendent.") .
							"</br>" .
							$this->l->t('%1$sContact support%2$s for more information.', ["<a href='mailto:info@sendent.com' style='color:blue'>", "</a>"]);
						$statusKind = "check";
					}
					elseif ($result[0]->isLicenseSuspended()) {
						$status = $this->l->t("Current license has been suspended by Sendent.") .
							"</br>" .
							$this->l->t('%1$sContact support%2$s for more information.', ["<a href='mailto:info@sendent.com' style='color:blue'>", "</a>"]);
						$statusKind = "check";
					} 
					elseif ($result[0]->isLicenseExpired()) {
						$status = $this->l->t("Current license has expired.") .
							"</br>" .
							$this->l->t('%1$sContact support%2$s if you experience issues with configuring your license key.', ["<a href='mailto:info@sendent.com' style='color:blue'>", "</a>"]);
						$statusKind = "expired";
					}  
					elseif (!$result[0]->isCheckNeeded() && !$result[0]->isLicenseExpired()) {
						$status = $this->l->t("Current license is valid");
						$statusKind = "valid";
					} 
					elseif (!$this->licensemanager->isWithinUserCount($result[0]) && $this->licensemanager->isWithinGraceUserCount($result[0])) {
						$status = $this->l->t("Current amount of active users exceeds licensed amount. Some users might not be able to use Sendent.");
						$statusKind = "userlimit";
					} 
					elseif (!$this->licensemanager->isWithinUserCount($result[0]) && !$this->licensemanager->isWithinGraceUserCount($result[0])) {
						$status = $this->l->t("Current amount of active users exceeds licensed amount. Additional users trying to use Sendent will be prevented from doing so.");
						$statusKind = "userlimit";
					}
					return new DataResponse(new LicenseStatus($status, $statusKind, $level,$licensekey, $dateExpiration, $dateLastCheck, $email, $product));
				} 
				elseif (count($result) > 0 && $result[0]->getLevel() == "Error_incomplete") {
					$email = $result[0]->getEmail();
					$licensekey = $result[0]->getLicensekey();
					$status = $this->l->t('Missing (or incorrect) email address or license key. %1$sContact support%2$s to get your correct license information.', ["<a href='mailto:support@sendent.nl' style='color:blue'>", "</a>"]);
					return new DataResponse(new LicenseStatus($status, "error_incomplete" ,"-", $licensekey, "-", "-", $email, $group));
				} 
				elseif (count($result) > 0 && $result[0]->getLevel() == License::ERROR_VALIDATING) {
					$email = $result[0]->getEmail();
					$licensekey = $result[0]->getLicensekey();
					return new DataResponse(new LicenseStatus($this->l->t("Cannot verify your license. Please make sure your licensekey and email address are correct before you try to 'Activate license'."), "error_validating","-", $licensekey, "-", "-", $email));
				} 
				else {
					return new DataResponse(new LicenseStatus($this->l->t("No license configured"), "nolicense" ,"-", "-", "-", "-", "-"));
				}
			} 
			else {
				return new DataResponse(new LicenseStatus($this->l->t("No license configured"), "nolicense" ,"-", "-", "-", "-", "-"));
			}
		} catch (Exception $e) {
			$this->logger->error('Cannot verify license');
			return new DataResponse(new LicenseStatus($this->l->t("Cannot verify your license. Please make sure your licensekey and email address are correct before you try to 'Activate license'."), "fatal" ,"-", "-", "-", "-", "-"));
		}
	}
/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * Returns license status for group $ncgroup
	 *
	 * @return DataResponse
	 */
	public function showInternal(): DataResponse {


			$this->logger->info('Getting license information for default group');

		try {
			// Gets license for group $ncgroup
			$result = $this->licenseservice->findAll();
			
			if (isset($result) && $result !== null && $result !== false) {
				if (is_array($result) && count($result) > 0
				&& $result[0]->getLevel() != "Error_clear" && $result[0]->getLevel() != "Error_incomplete") {


					$this->logger->info('Check needed for license ' . $result[0]->getId());
					try {
						$this->licensemanager->renewLicense($result[0]);
						$result = $this->licenseservice->findAll();
						if (isset($result) && $result !== null && $result !== false) {
							if (is_array($result) && count($result) > 0
							&& $result[0]->getLevel() != "Error_clear" && $result[0]->getLevel() != "Error_incomplete") {
							} else {
								//throw new Exception();
							}
						}
					} catch (Exception $e) {
						$this->logger->error('Error while renewing license ' . $result[0]->getId() . '\r\n' . $e);
					}

					// Reports license status
					$email = $result[0]->getEmail();
					$licensekey = $result[0]->getLicensekey();
					$this->logger->info('License key returned is: ' . $licensekey);
					$dateExpiration = $result[0]->getDatelicenseend();
					$dateLastCheck = $result[0]->getDatelastchecked();
					$level = $result[0]->getLevel();
					$product = $result[0]->getProduct();
					$statusKind = "";
					$status = "";

					if ($result[0]->isCleared()) {
						$status = $this->l->t("No license configured");
						$statusKind = "nolicense";
					} 
					elseif ($result[0]->isIncomplete()) {
						$status = $this->l->t("Missing email address or license key.");
						$statusKind = "error_incomplete";
					} 
					elseif ($result[0]->isCheckNeeded()) {
						$status = $this->l->t("Revalidation of your license is required");
						$statusKind = "check";
					}
					elseif (!$result[0]->isCheckNeeded() && !$result[0]->isLicenseExpired() && !$result[0]->isSupportedProduct()) {
						$status = $this->l->t("Current license is not intended to be used with Sendent Synchroniser. It is only intended to be used for configuring: " . $product) .
							"</br>" .
							$this->l->t('%1$sContact support%2$s if you experience issues with configuring your license key.', ["<a href='mailto:info@sendent.com' style='color:blue'>", "</a>"]);
						$statusKind = "expired";
					} 
					elseif ($result[0]->isLicenseRenewedOrSwitched()) {
						$status = $this->l->t("Current license has been changed.") .
							"</br>" .
							$this->l->t('%1$sContact support%2$s if you experience issues with configuring your license key.', ["<a href='mailto:info@sendent.com' style='color:blue'>", "</a>"]);
						$statusKind = "check";
					}
					elseif ($result[0]->isLicenseInactive()) {
						$status = $this->l->t("Current license has been deactivated by Sendent.") .
							"</br>" .
							$this->l->t('%1$sContact support%2$s for more information.', ["<a href='mailto:info@sendent.com' style='color:blue'>", "</a>"]);
						$statusKind = "check";
					}
					elseif ($result[0]->isLicenseSuspended()) {
						$status = $this->l->t("Current license has been suspended by Sendent.") .
							"</br>" .
							$this->l->t('%1$sContact support%2$s for more information.', ["<a href='mailto:info@sendent.com' style='color:blue'>", "</a>"]);
						$statusKind = "check";
					} 
					elseif ($result[0]->isLicenseExpired()) {
						$status = $this->l->t("Current license has expired.") .
							"</br>" .
							$this->l->t('%1$sContact support%2$s if you experience issues with configuring your license key.', ["<a href='mailto:info@sendent.com' style='color:blue'>", "</a>"]);
						$statusKind = "expired";
					}  
					elseif (!$result[0]->isCheckNeeded() && !$result[0]->isLicenseExpired() && $result[0]->isSupportedProduct()) {
						$status = $this->l->t("Current license is valid");
						$statusKind = "valid";
					} 
					elseif (!$result[0]->isCheckNeeded() && !$result[0]->isLicenseExpired()) {
						$status = $this->l->t("Current license is valid but there could be an issue for the supported products: " . $product);
						$statusKind = "valid";
					} 
					elseif (!$this->licensemanager->isWithinUserCount($result[0]) && $this->licensemanager->isWithinGraceUserCount($result[0])) {
						$status = $this->l->t("Current amount of active users exceeds licensed amount. Some users might not be able to use Sendent.");
						$statusKind = "userlimit";
					} 
					elseif (!$this->licensemanager->isWithinUserCount($result[0]) && !$this->licensemanager->isWithinGraceUserCount($result[0])) {
						$status = $this->l->t("Current amount of active users exceeds licensed amount. Additional users trying to use Sendent will be prevented from doing so.");
						$statusKind = "userlimit";
					}
					return new DataResponse(new LicenseStatus($status, $statusKind, $level,$licensekey, $dateExpiration, $dateLastCheck, $email, $product));
				} 
				elseif (count($result) > 0 && $result[0]->getLevel() == "Error_incomplete") {
					$email = $result[0]->getEmail();
					$licensekey = $result[0]->getLicensekey();
					$group = $result[0]->getNcgroup();
					$status = $this->l->t('Missing (or incorrect) email address or license key. %1$sContact support%2$s to get your correct license information.', ["<a href='mailto:support@sendent.nl' style='color:blue'>", "</a>"]);
					return new DataResponse(new LicenseStatus($status, "error_incomplete" ,"-", $licensekey, "-", "-", $email));
				} 
				elseif (count($result) > 0 && $result[0]->getLevel() == License::ERROR_VALIDATING) {
					$email = $result[0]->getEmail();
					$licensekey = $result[0]->getLicensekey();
					$group = $result[0]->getNcgroup();
					return new DataResponse(new LicenseStatus($this->l->t("Cannot verify your license. Please make sure your licensekey and email address are correct before you try to 'Activate license'."), "error_validating","-", $licensekey, "-", "-", $email));
				} 
				else {
					return new DataResponse(new LicenseStatus($this->l->t("No license configured"), "nolicense" ,"-", "-", "-", "-", "-"));
				}
			} 
			else {
				return new DataResponse(new LicenseStatus($this->l->t("No license configured"), "nolicense" ,"-", "-", "-", "-", "-"));
			}
		} catch (Exception $e) {
			$this->logger->error('Cannot verify license');
			return new DataResponse(new LicenseStatus($this->l->t("Cannot verify your license. Please make sure your licensekey and email address are correct before you try to 'Activate license'."), "fatal" ,"-", "-", "-", "-", "-"));
		}
	}
	/**
	 * @param string $license
	 * @param string $email
	 */
	public function create(string $license, string $email) {
		return $this->licensemanager->createLicense($license, '', '', $email);
	}

	/**
	 */
	public function delete() {
		// Deletes requested settinglicense
		return $this->licensemanager->deleteLicense();
	}
/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function renew() {
		// Finds out user's license
		$license = $this->licenseservice->findAll();
		// Unlicensed?
		if (is_null($license)) {
			return false;
		}
		$this->licensemanager->renewLicense($license[0]);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function validate() {
		// Finds out user's license
		$license = $this->licenseservice->findAll();

		// Unlicensed?
		if (is_null($license)) {
			return false;
		}

		$this->licensemanager->renewLicense($license[0]);
		return $this->licensemanager->isLocalValid($license[0]);
	}
}
