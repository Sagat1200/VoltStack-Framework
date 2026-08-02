<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors;

use Quantum\Controllers\Interceptors\ResolvedInterceptorDefinition;

final readonly class ControllerInterceptorPlan
{
    public function __construct(
        public array $interceptors,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->interceptors === [];
    }
}
