<?php

declare(strict_types=1);

namespace Quantum\Database\Runtime;

use Quantum\Database\Config\DatabaseConfiguration;
use VoltStack\Runtime\Context\RuntimeContext;

final class DatabaseExecutionScopeFactory
{
    public function create(?RuntimeContext $runtimeContext, DatabaseConfiguration $configuration): DatabaseExecutionScope
    {
        return new DatabaseExecutionScope(
            id: bin2hex(random_bytes(16)),
            runtimeRequestId: $runtimeContext?->requestId(),
            startedAt: $runtimeContext?->startedAt() ?? microtime(true),
            configuration: $configuration,
            metadata: [
                'transport' => $runtimeContext !== null ? 'runtime' : 'standalone',
            ],
        );
    }
}
