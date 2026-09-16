<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * The flush: everything above the flushed watermark becomes one signal. Under
 * the cap the refs travel inline; over it the signal is `truncated: true` and
 * the Connector pages /changes instead.
 *
 * The watermark only advances after a successful publish, so a dropped publish leaves the same refs for the next flush or the sweeper — duplicate delivery is free, missed delivery is what costs.
 */
class SignalPublisher {

	/**
	 * Nextcloud's job list rejects arguments whose JSON exceeds 32,000 chars.
	 * Past this threshold the webhook gets the truncated wire frame instead —
	 * a legal signal the Connector answers by paging /changes.
	 */
	private const MAX_WEBHOOK_ARGUMENT_BYTES = 30000;

	public function __construct(
		private BatchWindowService $window,
		private ChangeLedgerService $ledger,
		private SignalBuilder $builder,
		private NotifyPushTransport $transport,
		private ChangeNotificationConfig $config,
		private ITimeFactory $time,
		private SignalMetrics $metrics,
		private \OCP\BackgroundJob\IJobList $jobList,
		private LoggerInterface $logger,
	) {}

	/** In-request: flushes only when this request wins the batch window. @return array<string, mixed>|null */
	public function flushIfDue(): ?array {
		// Pinned to polling with no webhook: no signal channel exists, so
		// winning the window would only read rows and drop them.
		if ($this->config->transportMode() === \OCA\SendentSynchroniser\Constants::TRANSPORT_POLLING
			&& !$this->config->webhookEnabled()) {
			return null;
		}

		if (!$this->window->tryOpenWindow()) {
			return null;
		}

		return $this->flush();
	}

	/** Unconditional flush — sweeper, occ command, admin "flush now". @return array<string, mixed>|null */
	public function flush(): ?array {
		$since = $this->config->flushedSeq();
		$max = $this->config->maxRefsPerSignal();

		// One row past the cap tells us whether to truncate.
		$rows = $this->ledger->rows($since, $max + 1);
		if ($rows === []) {
			return null;
		}

		$truncated = count($rows) > $max;
		if ($truncated) {
			$this->logger->debug('Signal truncated at ' . $max . ' refs; the Connector will page /changes', [
				'app' => 'sendentsynchroniser',
			]);
		}
		$cursor = $this->ledger->highestSeqOf($rows);
		$refs = $truncated
			? []
			: array_map(static fn ($row) => $row->toReference($since), $rows);

		$signal = $this->builder->build($since, $cursor, $refs, $truncated);

		// Delivered if EITHER channel accepted it — a webhook-only setup must
		// still advance the watermark, or the sweeper re-delivers forever.
		$delivered = $this->transport->publish($signal);

		if ($this->config->webhookEnabled()) {
			$webhookSignal = $signal;
			if (strlen(json_encode($signal, JSON_THROW_ON_ERROR)) > self::MAX_WEBHOOK_ARGUMENT_BYTES) {
				$webhookSignal = $this->builder->build($since, $cursor, [], true);
			}
			try {
				$this->jobList->add(
					\OCA\SendentSynchroniser\BackgroundJob\SendWebhookSignal::class,
					['signal' => $webhookSignal, 'attempt' => 1]
				);
				$delivered = true;
			} catch (\Throwable $e) {
				// A failed enqueue must not wedge the flush.
				$this->logger->warning('Could not queue webhook signal: ' . $e->getMessage(), [
					'app' => 'sendentsynchroniser',
				]);
			}
		}

		if (!$delivered) {
			return null;
		}

		$this->config->setFlushedSeq($cursor);
		$this->config->setLastSignalAt($this->time->getTime());
		$this->metrics->recordFlush(count($refs), $truncated);

		return $signal;
	}
}
