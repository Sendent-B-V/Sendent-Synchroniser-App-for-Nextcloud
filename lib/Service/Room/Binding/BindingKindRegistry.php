<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: Sendent B.V. <l.vandebroek@sendent.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SendentSynchroniser\Service\Room\Binding;

class BindingKindRegistry {
	/** @var array<string, BindingValidator> */
	private array $byKind = [];

	/** @param BindingValidator[] $validators */
	public function __construct(array $validators) {
		foreach ($validators as $v) {
			$this->byKind[$v->kind()] = $v;
		}
	}

	public function get(string $kind): ?BindingValidator {
		return $this->byKind[$kind] ?? null;
	}

	/** @return string[] */
	public function kinds(): array {
		return array_keys($this->byKind);
	}
}
