<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\BackgroundJob;

use OCA\SendentSynchroniser\Service\ChangeNotification\ChangeNotificationConfig;
use OCA\SendentSynchroniser\Service\ChangeNotification\WebhookSigner;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Delivers one signal to the customer's webhook URL, off the request path.
 * A hint only: the Connector treats it exactly like a websocket frame and
 * still trusts the ledger, so retries are bounded (3) and failures are logged,
 * not escalated.
 *
 * Argument shape: ['signal' => array, 'attempt' => int]
 */
class SendWebhookSignal extends QueuedJob {

	private const MAX_ATTEMPTS = 3;

	public function __construct(
		ITimeFactory $time,
		private ChangeNotificationConfig $config,
		private WebhookSigner $signer,
		private IClientService $clientService,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$signal = $argument['signal'] ?? null;
		$attempt = (int)($argument['attempt'] ?? 1);

		if (!is_array($signal) || !$this->config->webhookEnabled()) {
			return;
		}

		$url = $this->config->webhookUrl();
		$secret = $this->config->webhookSecret();
		$body = json_encode($signal, JSON_THROW_ON_ERROR);
		$timestamp = $this->time->getTime();
		$nonce = bin2hex(random_bytes(8));

		try {
			$client = $this->clientService->newClient();
			$response = $client->post($url, [
				'body' => $body,
				'timeout' => 10,
				'headers' => [
					'Content-Type' => 'application/json',
					WebhookSigner::HEADER => $this->signer->headerValue(
						$timestamp,
						$nonce,
						$this->signer->sign($secret, $timestamp, $nonce, $body)
					),
				],
			]);
			$status = $response->getStatusCode();
			if ($status >= 200 && $status < 300) {
				return;
			}
			$this->retryOrGiveUp($signal, $attempt, 'HTTP ' . $status);
		} catch (\Throwable $e) {
			$this->retryOrGiveUp($signal, $attempt, $e->getMessage());
		}
	}

	/** @param array<string, mixed> $signal */
	private function retryOrGiveUp(array $signal, int $attempt, string $reason): void {
		if ($attempt >= self::MAX_ATTEMPTS) {
			$this->logger->warning('Webhook signal dropped after ' . $attempt . ' attempts: ' . $reason, [
				'app' => 'sendentsynchroniser',
			]);
			return;
		}

		$this->jobList->add(self::class, ['signal' => $signal, 'attempt' => $attempt + 1]);
	}
}
