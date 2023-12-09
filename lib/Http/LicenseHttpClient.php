<?php

namespace OCA\SendentSynchroniser\Http;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TransferException;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

class LicenseHttpClient {

	/** @var IClient */
	protected $client;

	/** @var LoggerInterface */
	protected $logger;

	/** @var string */
	protected $baseUrl;
	
	//public function __construct(IClientService $clientService, LoggerInterface $logger, string $baseUrl = "https://api.scwcloud.sendent.dev/") {
	public function __construct(IClientService $clientService, LoggerInterface $logger, string $baseUrl = "https://api.scwcloud.sendent.dev/") {
	//public function __construct(IClientService $clientService, LoggerInterface $logger, string $baseUrl = "http://localhost:8085/") {
		$this->client = $clientService->newClient();
		$this->logger = $logger;
		$this->baseUrl = $baseUrl;
	}

	public function get(string $request): bool {
		$response = $this->client->get($this->baseUrl . $request);

		return $response->getStatusCode() === Http::STATUS_OK;
	}
	public function getJson(string $request) {
		$response = $this->client->get($this->baseUrl . $request);
		return json_decode($response->getBody());
	}
	public function post(string $request, Dto\SubscriptionIn $data) {
		$uri = $this->baseUrl . $request;

		try {
			$response = $this->client->post($uri, [
				'json' => $data->jsonSerialize(),
				'header' => [
					'api-version' => '1',
				],
			]);
		} catch (BadResponseException $e) {
			$this->logger->warning('License client received error response with status '. $e->getResponse()->getStatusCode());

			return null;
		} catch (TransferException $e) {
			$this->logger->error('License client could not connect to license server: ' . $e->getMessage());

			return null;
		} catch(Exception $e){
			$this->logger->error('License client could not connect to license server. There was an undefined error: ' . $e->getMessage());
			return null;
		}

		if ($response->getStatusCode() === Http::STATUS_OK) {
			$this->logger->info('Successfully contacted license server');

			return json_decode($response->getBody());
		}

		$this->logger->error('Unknown error from license client: ' . $response->getStatusCode() . ' ' . $response->getBody());

		return null;
	}
	
	public function put(string $request, $data): bool {
		$uri = $this->baseUrl . $request;

		$response = $this->client->put($uri, [
			'json' => $data,
		]);

		return $response->getStatusCode() === Http::STATUS_OK;
	}
}
