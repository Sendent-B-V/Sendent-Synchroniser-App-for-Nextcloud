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
 *
 * Sequence numbers are handed out before the rows carrying them commit, so a
 * flush can see row 11 while row 10 is still uncommitted. Two rules keep row
 * 10 from ending up below the watermark, where no later flush would find it:
 *
 *  1. Before reading, the flush raises the fence (CursorService) to the
 *     counter's current value F. Every number at or below F was already handed
 *     out; numbers above F did not exist yet when the read started.
 *  2. The watermark never moves past F. A row above F that was still
 *     uncommitted therefore stays above the watermark for the next flush.
 *
 * A row at or below F that commits after the read sees the raised fence once
 * its writer has committed, and that writer re-stamps it above F
 * (ChangeLedgerService::confirm()). Rows above F that this flush did read are
 * read again next time: a rare duplicate, which readers absorb.
 *
 * Because the watermark can now trail what a signal carried, `prev` is the
 * previous signal's cursor (publishedSeq), not the watermark. That keeps the
 * Connector's gap check exact: a frame whose prev is above its last cursor
 * means a frame was missed.
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
		private CursorService $cursor,
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
		$state = $this->config->flushState(); // fresh: see ChangeNotificationConfig::flushState()
		$since = $state['flushed'];
		$max = $this->config->maxRefsPerSignal();

		// Rule 1: fence off every number handed out so far, before reading.
		$fence = $this->cursor->current();
		$this->cursor->raiseFence($fence);

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
		$highest = $this->ledger->highestSeqOf($rows);
		$prev = $state['published'];
		// Never below the previous signal's cursor: a flush that only re-reads
		// rows the last one carried must not look like the feed moved back.
		$cursor = max($highest, $prev);
		$refs = $truncated
			? []
			: array_map(static fn ($row) => $row->toReference($since), $rows);

		$signal = $this->builder->build($prev, $cursor, $refs, $truncated);

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

		// Rule 2: the read watermark stops at the fence.
		$this->config->recordFlush(min($highest, $fence), $cursor);
		$this->config->setLastSignalAt($this->time->getTime());
		$this->metrics->recordFlush(count($refs), $truncated);

		return $signal;
	}
}
