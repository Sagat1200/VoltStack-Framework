<?php

declare(strict_types=1);

namespace Quantum\Database\Execution;

final readonly class ExecutionContext
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public ?string $correlationId = null,
        public ?int $timeoutMs = null,
        public array $metadata = [],
    ) {
    }
}
