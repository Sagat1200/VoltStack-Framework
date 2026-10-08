<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Model;

final readonly class TransportPlan
{
    /**
     * @param array<string, string> $headers
     * @param array<string, scalar|array|null> $metadata
     */
    public function __construct(
        public string $target,
        public ?int $status = null,
        public ?int $exitCode = null,
        public array $headers = [],
        public ?string $spaAction = null,
        public ?int $retryAfterSeconds = null,
        public array $metadata = [],
    ) {
        if ($target === '') {
            throw new \InvalidArgumentException('TransportPlan target must not be empty.');
        }

        if ($retryAfterSeconds !== null && $retryAfterSeconds < 0) {
            throw new \InvalidArgumentException('TransportPlan retryAfterSeconds must be greater than or equal to zero.');
        }
    }
}
