<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Bridges\Runtime;

final readonly class RuntimeOperation
{
    /**
     * @param array<string, scalar|array|null> $metadata
     */
    public function __construct(
        public string $transport,
        public string $operationId,
        public ?float $deadlineAt = null,
        public array $metadata = [],
    ) {
        if ($transport === '') {
            throw new \InvalidArgumentException('RuntimeOperation transport must not be empty.');
        }

        if ($operationId === '') {
            throw new \InvalidArgumentException('RuntimeOperation operationId must not be empty.');
        }
    }
}
