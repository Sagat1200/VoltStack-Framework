<?php

declare(strict_types=1);

namespace Quantum\Database\Query;

final readonly class QueryMetadata
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public ?string $connectionName = null,
        public array $context = [],
    ) {
    }
}
