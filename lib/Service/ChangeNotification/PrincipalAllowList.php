<?php
declare(strict_types=1);

namespace OCA\SendentSynchroniser\Service\ChangeNotification;

use OCA\SendentSynchroniser\Constants;
use OCP\AppFramework\Services\IAppConfig;

/**
 * Optional privacy hardening: when enabled, /changes only returns refs for
 * principals the Connector has uploaded. Off by default (allow-all).
 *
 * Fail-closed: enabled + empty allowlist returns nothing, so enabling before
 * uploading never leaks refs.
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
		// Cheap insurance against a runaway upload; guard-authenticated callers only.
		$clean = array_slice($clean, 0, 100000);
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
