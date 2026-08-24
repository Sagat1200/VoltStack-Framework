<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

final readonly class DatabaseDiagnosticEvent
{
    /**
     * @param array<string, scalar|null> $details
     */
    public function __construct(
        public string $name,
        public string $at,
        public array $details = [],
    ) {
    }
}
