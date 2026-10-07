<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Runtime;

use VoltStack\Runtime\Context\RuntimeContext as WorkerRuntimeContext;

final class ExceptionScopeFactory
{
    public function create(
        ?WorkerRuntimeContext $runtimeContext = null,
        ?ExceptionRuntimeLimits $limits = null,
    ): ExceptionScope {
        $resolvedLimits = $limits ?? new ExceptionRuntimeLimits();

        return new ExceptionScope(
            id: bin2hex(random_bytes(16)),
            runtimeRequestId: $runtimeContext?->requestId(),
            startedAt: $runtimeContext?->startedAt() ?? microtime(true),
            limits: $resolvedLimits,
            registry: new OccurrenceRegistry($resolvedLimits->maxOccurrences),
            metadata: [
                'transport' => $runtimeContext !== null ? 'runtime' : 'standalone',
            ],
        );
    }
}
