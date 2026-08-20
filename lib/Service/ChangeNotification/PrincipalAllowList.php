<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Constants;
use OCP\AppFramework\Services\IAppConfig;

/**
 * Optional privacy hardening: when a customer considers collection URIs
 * sensitive, the Connector uploads the principals it actually maps, and
 * /changes stops returning refs for anyone else. Off by default; disabled
 * means allow-all.
 *
 * Fail-closed on purpose: enabled + empty = nothing is returned, so a
 * Connector that enables the list before uploading it sees an empty feed
 * rather than a leak.
 */
class PrincipalAllowList {

	public function __construct(
		private IAppConfig $appConfig,
		private ChangeNotificationConfig $config,
	) {}

	public function isAllowed(string $principalUri): bool {
		if (!$this->config->allowListEnabled()) {
			return true;
		}

		return in_array($principalUri, $this->principals(), true);
	}

	/** @param string[] $principals */
	public function replace(array $principals): void {
		$clean = array_values(array_unique(array_filter($principals, static fn ($p) => is_string($p) && $p !== '')));
		$this->appConfig->setAppValue(Constants::CN_ALLOWLIST_KEY, json_encode($clean, JSON_THROW_ON_ERROR));
	}

	public function count(): int {
		return count($this->principals());
	}

	/** @return string[] */
	private function principals(): array {
		$raw = json_decode((string)$this->appConfig->getAppValue(Constants::CN_ALLOWLIST_KEY, '[]'), true);

		return is_array($raw) ? $raw : [];
	}
}
