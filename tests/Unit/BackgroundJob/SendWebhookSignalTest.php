<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Tests\Unit\BackgroundJob;

use OCA\SendentSynchroniser\BackgroundJob\SendWebhookSignal;
use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\WebhookSigner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SendWebhookSignalTest extends TestCase {

	private const SIGNAL = ['v' => 1, 'prev' => 0, 'cursor' => 5, 'truncated' => false, 'refs' => []];

	/** @var ChangeNotificationConfig&MockObject */
	private $config;

	/** @var IClientService&MockObject */
	private $clientService;

	/** @var IClient&MockObject */
	private $client;

	/** @var IJobList&MockObject */
	private $jobList;

	private SendWebhookSignal $job;

	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(ChangeNotificationConfig::class);
		$this->config->method('webhookUrl')->willReturn('https://connector.example.com/hook');
		$this->config->method('webhookSecret')->willReturn('secret');
		$this->clientService = $this->createMock(IClientService::class);
		$this->client = $this->createMock(IClient::class);
		$this->clientService->method('newClient')->willReturn($this->client);
		$this->jobList = $this->createMock(IJobList::class);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1755676800);

		$this->job = new SendWebhookSignal(
			$time,
			$this->config,
			new WebhookSigner(),
			$this->clientService,
			$this->jobList,
			new NullLogger(),
		);
	}

	private function respondWith(int $status): void {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$this->client->method('post')->willReturn($response);
	}

	public function testADisabledWebhookSendsNothing(): void {
		$this->config->method('webhookEnabled')->willReturn(false);
		$this->clientService->expects($this->never())->method('newClient');

		$this->job->setArgument(['signal' => self::SIGNAL, 'attempt' => 1]);
		$this->job->start($this->createMock(IJobList::class));
	}

	public function testASuccessfulDeliveryDoesNotRetry(): void {
		$this->config->method('webhookEnabled')->willReturn(true);
		$this->respondWith(200);
		$this->jobList->expects($this->never())->method('add');

		$this->job->setArgument(['signal' => self::SIGNAL, 'attempt' => 1]);
		$this->job->start($this->createMock(IJobList::class));
	}

	public function testAServerErrorRequeuesWithTheNextAttempt(): void {
		$this->config->method('webhookEnabled')->willReturn(true);
		$this->respondWith(500);
		$this->jobList->expects($this->once())
			->method('add')
			->with(SendWebhookSignal::class, ['signal' => self::SIGNAL, 'attempt' => 2]);

		$this->job->setArgument(['signal' => self::SIGNAL, 'attempt' => 1]);
		$this->job->start($this->createMock(IJobList::class));
	}

	public function testTheLastAttemptGivesUpWithoutRequeueing(): void {
		$this->config->method('webhookEnabled')->willReturn(true);
		$this->client->method('post')->willThrowException(new \RuntimeException('connection refused'));
		$this->jobList->expects($this->never())->method('add');

		$this->job->setArgument(['signal' => self::SIGNAL, 'attempt' => 3]);
		$this->job->start($this->createMock(IJobList::class));
	}

	public function testTheRequestCarriesTheSignatureHeader(): void {
		$this->config->method('webhookEnabled')->willReturn(true);
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$this->client->expects($this->once())
			->method('post')
			->with(
				'https://connector.example.com/hook',
				$this->callback(function (array $options): bool {
					$header = $options['headers'][WebhookSigner::HEADER] ?? '';
					return is_string($header) && preg_match('/^t=\d+,n=[0-9a-f]{16},s=[0-9a-f]{64}$/', $header) === 1;
				})
			)
			->willReturn($response);

		$this->job->setArgument(['signal' => self::SIGNAL, 'attempt' => 1]);
		$this->job->start($this->createMock(IJobList::class));
	}
}
