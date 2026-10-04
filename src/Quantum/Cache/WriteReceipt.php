<?php

declare(strict_types=1);

namespace Quantum\Cache;

final readonly class WriteReceipt
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public string $operationId,
        public Effect $effect,
        public ?string $writeId = null,
        public array $details = [],
    ) {}
}
