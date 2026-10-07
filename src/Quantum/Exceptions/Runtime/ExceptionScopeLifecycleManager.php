<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Runtime;

use VoltStack\Runtime\Context\RuntimeContext as WorkerRuntimeContext;

final class ExceptionScopeLifecycleManager
{
    public function __construct(
        private readonly ExceptionScopeFactory $factory = new ExceptionScopeFactory(),
        private readonly ?ExceptionRuntimeLimits $limits = null,
    ) {
    }

    public function start(?WorkerRuntimeContext $context = null): ExceptionScope
    {
        $scope = $this->factory->create($context, $this->limits);

        if ($context !== null) {
            $context->set('exceptions.scope_id', $scope->id());
        }

        return $scope;
    }

    public function end(ExceptionScope $scope, ?WorkerRuntimeContext $context = null): void
    {
        $scope->finalize();

        if ($context === null) {
            return;
        }

        $context->set('exceptions.scope_id', $scope->id());
        $context->set('exceptions.scope_finalized', true);
    }
}
