<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.pasmans@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service\Room\Binding;

interface BindingValidator {
	/** Discriminator value (e.g. 'exchange'). */
	public function kind(): string;

	/**
	 * @param string $externalId User-supplied id (e.g. mailbox)
	 * @param array $config      Kind-specific opaque config
	 * @return array{externalId: string, config: array<string, mixed>}
	 * @throws BindingValidationException
	 */
	public function validate(string $externalId, array $config): array;
}
