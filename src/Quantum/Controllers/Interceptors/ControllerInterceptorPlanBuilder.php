<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors;

use Quantum\Controllers\Interceptors\ControllerInterceptorPlan;
use Quantum\Controllers\Interceptors\ResolvedInterceptorDefinition;

final class ControllerInterceptorPlanBuilder
{
    public function build(array $definitions): ControllerInterceptorPlan
    {
        $sorted = $definitions;

        usort($sorted, static function (ResolvedInterceptorDefinition $a, ResolvedInterceptorDefinition $b): int {
            if ($a->priority === $b->priority) {
                return $a->orderIndex <=> $b->orderIndex;
            }

            return $b->priority <=> $a->priority;
        });

        return new ControllerInterceptorPlan($sorted);
    }
}
