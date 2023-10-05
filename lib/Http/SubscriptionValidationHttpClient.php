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

	/** @var LoggerInterface */
	protected $logger;

	/** @var SyncUserService */
	private $syncUserService;

	public function __construct(LicenseHttpClient $licenseHttpClient, SyncUserService $syncUserService, LoggerInterface $logger) {

		$this->licenseHttpClient = $licenseHttpClient;
		$this->logger = $logger;
		$this->syncUserService = $syncUserService;

	}

	public function validate(License $licenseData, $connectedUserCount = null): ?License {
		$this->logger->info('SUBSCRIPTIONVALIDATIONHTTPCLIENT-VALIDATE');
		error_log(print_r('SUBSCRIPTIONVALIDATIONHTTPCLIENT-VALIDATE', true));

		if ($licenseData->getLicensekey() === "" || $licenseData->getEmail() === "") {
			$this->logger->info('SUBSCRIPTIONVALIDATIONHTTPCLIENT-VALIDATE: No key or email information found for license');
			error_log(print_r('SUBSCRIPTIONVALIDATIONHTTPCLIENT-VALIDATE: No key or email information found for license', true));
			return null;
		}

		// TODO
		$connectedUserCount = $connectedUserCount?? count($this->syncUserService->getValidUsers());

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
				$validatedLicense->setMaxgraceusers($result->amountUsersMax);
				$validatedLicense->setDatelastchecked(date_format(date_create("now"), "Y-m-d"));

				$this->logger->info('SUBSCRIPTIONVALIDATIONHTTPCLIENT-LEVEL=		' . $result->level);
				error_log(print_r('SUBSCRIPTIONVALIDATIONHTTPCLIENT-LEVEL=		' . $result->level, true));

				return $validatedLicense;
			}
			else
			{
				$validatedLicense->setLevel(License::ERROR_VALIDATING);
				error_log(print_r("SUBSCRIPTIONVALIDATIONHTTPCLIENT-VALIDATE SETTING LEVEL TO ERROR_VALIDATING", true));

			}
		} catch (Exception $e) {
			$this->logger->error('SUBSCRIPTIONVALIDATIONHTTPCLIENT-VALIDATE-EXCEPTION: ' . $e->getMessage(), [
				'exception' => $e,
			]);
			error_log(print_r('SUBSCRIPTIONVALIDATIONHTTPCLIENT-VALIDATE-EXCEPTION: ' . $e->getMessage(), true));

			$validatedLicense->setLevel(License::ERROR_VALIDATING);
		}

		return $validatedLicense;
	}

	public function activate(License $licenseData): ?License {
		$this->logger->info('SUBSCRIPTIONVALIDATIONHTTPCLIENT-ACTIVATE');

		return $this->validate($licenseData, 1);
	}
}
