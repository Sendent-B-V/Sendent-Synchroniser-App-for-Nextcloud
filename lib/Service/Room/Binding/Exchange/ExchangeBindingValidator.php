<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service\Room\Binding\Exchange;

use OCA\SendentSynchroniser\Service\Room\Binding\BindingValidationException;
use OCA\SendentSynchroniser\Service\Room\Binding\BindingValidator;

class ExchangeBindingValidator implements BindingValidator {
	public function kind(): string {
		return 'exchange';
	}

	public function validate(string $externalId, array $config): array {
		$normalized = strtolower(trim($externalId));
		if ($normalized === '') {
			throw new BindingValidationException('externalId is required for exchange binding');
		}
		if (!filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
			throw new BindingValidationException('externalId must be a valid SMTP/UPN: ' . $externalId);
		}
		return ['externalId' => $normalized, 'config' => $config];
	}
}
