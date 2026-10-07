<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Model;

use Quantum\Exceptions\Model\FailureSnapshot;
use Quantum\Exceptions\Model\SemanticError;

final readonly class ExceptionDescriptor
{
    /**
     * @param array<string, scalar|array|null> $contextSummary
     */
    public function __construct(
        public string $occurrenceId,
        public array $contextSummary,
        public FailureSnapshot $failure,
        public SemanticError $semantic,
        public string $policyRevision = 'v1',
    ) {
        if ($occurrenceId === '') {
            throw new \InvalidArgumentException('ExceptionDescriptor occurrenceId must not be empty.');
        }

        if ($policyRevision === '') {
            throw new \InvalidArgumentException('ExceptionDescriptor policyRevision must not be empty.');
        }
    }
}
