<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * The flush: everything in the ledger above the flushed watermark becomes one
 * signal. Under the cap the refs travel inline; over it the signal degrades to
 * `truncated: true` and the Connector pages /changes — under extreme load the
 * system deliberately shifts from push-refs to read-the-ledger, the cheaper
 * path.
 *
 * The watermark (cnFlushedSeq) only advances after a successful publish, so a
 * dropped publish means the same refs ride the next flush or the sweeper's.
 * Duplicate delivery is free by design; missed delivery is what costs.
 */
class SignalPublisher {

	public function __construct(
		private BatchWindowService $window,
		private ChangeLedgerService $ledger,
		private SignalBuilder $builder,
		private NotifyPushTransport $transport,
		private ChangeNotificationConfig $config,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {}

	/**
	 * In-request path: flush only when this request wins the batch window.
	 *
	 * @return array<string, mixed>|null the published signal
	 */
	public function flushIfDue(): ?array {
		if (!$this->window->tryOpenWindow()) {
			return null;
		}

		return $this->flush();
	}

	/**
	 * Unconditional flush — sweeper, occ command, admin "flush now".
	 *
	 * @return array<string, mixed>|null the published signal
	 */
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

		if (!$this->transport->publish($signal)) {
			return null;
		}

		$this->config->setFlushedSeq($cursor);
		$this->config->setLastSignalAt($this->time->getTime());

		return $signal;
	}
}
