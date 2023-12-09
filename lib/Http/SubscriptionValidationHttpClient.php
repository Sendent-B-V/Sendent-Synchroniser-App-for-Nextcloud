<?php

namespace OCA\SendentSynchroniser\Http;

use Exception;
use OCA\SendentSynchroniser\Db\License;
use OCA\SendentSynchroniser\Http\Dto\SubscriptionIn;
use OCA\SendentSynchroniser\Service\SyncUserService;
use Psr\Log\LoggerInterface;


class SubscriptionValidationHttpClient {
	/** @var LicenseHttpClient */
	protected $licenseHttpClient;

	/** @var ConnectedUserService */
	protected $syncuserService;

	/** @var LoggerInterface */
	protected $logger;

	public function __construct(LicenseHttpClient $licenseHttpClient, SyncUserService $syncuserService, LoggerInterface $logger) {
		$this->licenseHttpClient = $licenseHttpClient;
		$this->syncuserService = $syncuserService;
		$this->logger = $logger;
	}

	public function validate(License $licenseData, $connectedUserCount = null): ?License {
		$this->logger->info('SUBSCRIPTIONVALIDATIONHTTPCLIENT-VALIDATE');
		error_log(print_r('SUBSCRIPTIONVALIDATIONHTTPCLIENT-VALIDATE', true));

		if ($licenseData->getLicensekey() === "" || $licenseData->getEmail() === "") {
			$this->logger->info('SUBSCRIPTIONVALIDATIONHTTPCLIENT-VALIDATE: No key or email information found for license');
			error_log(print_r('SUBSCRIPTIONVALIDATIONHTTPCLIENT-VALIDATE: No key or email information found for license', true));
			return null;
		}

		$connectedUserCount = $connectedUserCount ?? $this->syncuserService->getValidUserCount();

		$this->logger->info('SUBSCRIPTIONVALIDATIONHTTPCLIENT-USERCOUNT= ' . $connectedUserCount);
		error_log(print_r('SUBSCRIPTIONVALIDATIONHTTPCLIENT-USERCOUNT= ' . $connectedUserCount, true));

		$data = new SubscriptionIn($licenseData, $connectedUserCount);

		$validatedLicense = new License();
		$validatedLicense->setId($licenseData->getId());
		$validatedLicense->setLicensekey($licenseData->getLicensekey());
		$validatedLicense->setEmail($licenseData->getEmail());
		
		try {
			$result = $this->licenseHttpClient->post('subscription/validate', $data);

			if (isset($result) && $result != null) {
				$validatedLicense->setLevel($result->level);
				
				$validatedLicense->setDategraceperiodend(date_format(date_create($result->gracePeriodEnd), "Y-m-d"));
				$validatedLicense->setDatelicenseend(date_format(date_create($result->expirationDate), "Y-m-d"));
				$validatedLicense->setMaxusers($result->amountUsers);
				$validatedLicense->setLicensekeytoken($result->key);
				$validatedLicense->setSubscriptionstatus($result->subscriptionStatus);
				$validatedLicense->setMaxgraceusers($result->amountUsersMax);
				$validatedLicense->setDatelastchecked(date_format(date_create("now"), "Y-m-d"));
				$validatedLicense->setIstrial($result->isTrial);
				$validatedLicense->setTechnicallevel($result->technicalProductLevel);
				$validatedLicense->setProduct($result->product);

				$this->logger->info('SUBSCRIPTIONVALIDATIONHTTPCLIENT-LEVEL=		' . $result->level);
				$this->logger->info('SUBSCRIPTIONVALIDATIONHTTPCLIENT-KEY=			' . $result->key);

				error_log(print_r('SUBSCRIPTIONVALIDATIONHTTPCLIENT-LEVEL=		' . $result->level, true));

				return $validatedLicense;
			}
			else
			{
				$validatedLicense->setLevel(License::ERROR_VALIDATING);
				$validatedLicense->setSubscriptionstatus(License::ERROR_VALIDATING);
				$validatedLicense->setIstrial(-1);
				$validatedLicense->setTechnicallevel(License::ERROR_VALIDATING);
				$validatedLicense->setProduct(License::ERROR_VALIDATING);
				error_log(print_r("SUBSCRIPTIONVALIDATIONHTTPCLIENT-VALIDATE SETTING LEVEL TO ERROR_VALIDATING", true));

			}
		} catch (Exception $e) {
			$this->logger->error('SUBSCRIPTIONVALIDATIONHTTPCLIENT-VALIDATE-EXCEPTION: ' . $e->getMessage(), [
				'exception' => $e,
			]);
			error_log(print_r('SUBSCRIPTIONVALIDATIONHTTPCLIENT-VALIDATE-EXCEPTION: ' . $e->getMessage(), true));
			$validatedLicense->setSubscriptionstatus(License::ERROR_VALIDATING);
			$validatedLicense->setLevel(License::ERROR_VALIDATING);
			$validatedLicense->setIstrial(-1);
			$validatedLicense->setTechnicallevel(License::ERROR_VALIDATING);
			$validatedLicense->setProduct(License::ERROR_VALIDATING);
		}

		return $validatedLicense;
	}

	public function activate(License $licenseData): ?License {
		$this->logger->info('SUBSCRIPTIONVALIDATIONHTTPCLIENT-ACTIVATE');

		return $this->validate($licenseData, 1);
	}
}
