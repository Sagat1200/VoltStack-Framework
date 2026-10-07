<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Context;

use Quantum\Exceptions\Enums\Effect;

final readonly class RecoveryContext
{
    /**
     * @param array<string, bool|int|string> $capabilities
     */
    public function __construct(
        public string $owner,
        public Effect $effect = Effect::Unknown,
        public bool $idempotencyVerified = false,
        public int $attempt = 0,
        public ?int $deadlineMs = null,
        public bool $cancelled = false,
        public array $capabilities = [],
    ) {
        if ($owner === '') {
            throw new \InvalidArgumentException('Recovery owner must not be empty.');
        }

        if ($attempt < 0) {
            throw new \InvalidArgumentException('Recovery attempt must be greater than or equal to zero.');
        }

        if ($deadlineMs !== null && $deadlineMs <= 0) {
            throw new \InvalidArgumentException('Recovery deadlineMs must be greater than zero when provided.');
        }
    }
}
