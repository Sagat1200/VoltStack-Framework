<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors;

use Quantum\Controllers\Execution\ControllerExecution;
use Quantum\Controllers\Interceptors\Conditions\Contracts\InterceptorConditionInterface;
use Quantum\Controllers\Interceptors\InterceptorPhase;
use Quantum\Controllers\Interceptors\InterceptorScope;

final readonly class ResolvedInterceptorDefinition
{
    public function __construct(
        public string $interceptorClass,
        public array $arguments,
        public int $priority,
        public InterceptorPhase $phase,
        public InterceptorScope $scope,
        public int $orderIndex,
        public array $conditions = [],
        public bool $repeatable = false,
    ) {}

    public function matches(ControllerExecution $execution): bool
    {
        foreach ($this->conditions as $condition) {
            if (! $condition instanceof InterceptorConditionInterface) {
                return false;
            }

            if (! $condition->matches($execution, $this)) {
                return false;
            }
        }

        return true;
    }
}
