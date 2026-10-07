<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Model;

use Quantum\Exceptions\Enums\Effect;
use Quantum\Exceptions\Enums\RetryAdvice;
use Quantum\Exceptions\Enums\SemanticCategory;
use Quantum\Exceptions\Enums\SemanticSeverity;

final readonly class SemanticError
{
    /**
     * @param array<string, scalar|array|null> $safeParameters
     */
    public function __construct(
        public string $code,
        public SemanticCategory $category,
        public string $messageKey,
        public array $safeParameters = [],
        public SemanticSeverity $severity = SemanticSeverity::Error,
        public Effect $effect = Effect::Unknown,
        public RetryAdvice $retryAdvice = RetryAdvice::Never,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.]{0,95}$/', $code)) {
            throw new \InvalidArgumentException('SemanticError code must match the public code format.');
        }

        if ($messageKey === '') {
            throw new \InvalidArgumentException('SemanticError messageKey must not be empty.');
        }
    }
}
