<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors;

use Quantum\Controllers\Interceptors\InterceptorPhase;
use Quantum\Controllers\Interceptors\InterceptorScope;

final readonly class InterceptorDefinition
{
    public function __construct(
        public string $interceptor,
        public array $arguments = [],
        public ?int $priority = null,
        public array $conditions = [],
        public ?InterceptorPhase $phase = null,
        public ?InterceptorScope $scope = null,
    ) {}
}