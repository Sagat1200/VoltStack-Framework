<?php

declare(strict_types=1);

namespace Quantum\Database\Execution;

final readonly class ExecutionFailure
{
    /**
     * @param array<string, mixed> $diagnostics
     */
    public function __construct(
        public string $phase,
        public string $category,
        public string $message,
        public ?string $sqlState = null,
        public int|string|null $nativeCode = null,
        public bool $connectionReusable = true,
        public bool $retryable = false,
        public array $diagnostics = [],
    ) {
    }
}
