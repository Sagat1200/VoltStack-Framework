<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

final readonly class MigrationRecoveryAdvice
{
    /**
     * @param list<string> $recommendedCommands
     */
    public function __construct(
        public string $strategy,
        public string $summary,
        public array $recommendedCommands,
    ) {
    }
}
