<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Model;

use Quantum\Exceptions\Enums\RecoveryAction;

final readonly class RecoveryDecision
{
    public function __construct(
        public RecoveryAction $action,
        public string $reasonCode,
        public ?int $delayMs = null,
    ) {
        if ($reasonCode === '') {
            throw new \InvalidArgumentException('RecoveryDecision reasonCode must not be empty.');
        }

        if ($delayMs !== null && $delayMs < 0) {
            throw new \InvalidArgumentException('RecoveryDecision delayMs must be greater than or equal to zero.');
        }
    }
}
