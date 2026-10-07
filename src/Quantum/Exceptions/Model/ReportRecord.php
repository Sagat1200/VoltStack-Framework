<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Model;

final readonly class ReportRecord
{
    /**
     * @param array<string, scalar|array|null> $diagnostic
     * @param array<string, scalar|array|null> $correlation
     */
    public function __construct(
        public string $occurrenceId,
        public ?string $parentOccurrenceId,
        public string $fingerprint,
        public SemanticError $semantic,
        public array $diagnostic = [],
        public array $correlation = [],
    ) {
        if ($occurrenceId === '') {
            throw new \InvalidArgumentException('ReportRecord occurrenceId must not be empty.');
        }

        if ($fingerprint === '') {
            throw new \InvalidArgumentException('ReportRecord fingerprint must not be empty.');
        }
    }
}
