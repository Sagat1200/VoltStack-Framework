<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Context;

use Quantum\Exceptions\Runtime\ExceptionScope;

final readonly class ExceptionContext
{
    /**
     * @param array<string, scalar|array|null> $attributes
     */
    public function __construct(
        public string $scopeId,
        public ?string $occurrenceId = null,
        public ?string $correlationId = null,
        public ?string $locale = null,
        public bool $debug = false,
        public array $attributes = [],
        public ?ExceptionScope $scope = null,
    ) {
        if ($scopeId === '') {
            throw new \InvalidArgumentException('Exception scopeId must not be empty.');
        }
    }
}
