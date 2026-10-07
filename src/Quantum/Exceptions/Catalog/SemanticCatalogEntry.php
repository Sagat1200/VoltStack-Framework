<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Catalog;

use Quantum\Exceptions\Enums\Effect;
use Quantum\Exceptions\Enums\RetryAdvice;
use Quantum\Exceptions\Enums\SemanticCategory;
use Quantum\Exceptions\Enums\SemanticSeverity;

final readonly class SemanticCatalogEntry
{
    /**
     * @param list<string> $safeParameterKeys
     */
    public function __construct(
        public string $code,
        public SemanticCategory $category,
        public string $messageKey,
        public SemanticSeverity $severity = SemanticSeverity::Error,
        public Effect $effect = Effect::Unknown,
        public RetryAdvice $retryAdvice = RetryAdvice::Never,
        public ?string $publicDefault = null,
        public ?string $reportClass = null,
        public ?int $httpDefault = null,
        public ?int $cliDefault = null,
        public array $safeParameterKeys = [],
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.]{0,95}$/', $code)) {
            throw new \InvalidArgumentException('SemanticCatalogEntry code must match the public code format.');
        }

        if ($messageKey === '') {
            throw new \InvalidArgumentException('SemanticCatalogEntry messageKey must not be empty.');
        }
    }
}
