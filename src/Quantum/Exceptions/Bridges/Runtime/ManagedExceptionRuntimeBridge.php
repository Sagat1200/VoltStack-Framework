<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Bridges\Runtime;

use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Runtime\ExceptionScope;
use Quantum\Exceptions\Runtime\ExceptionScopeLifecycleManager;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\RuntimeContext;
use VoltStack\Runtime\Reset\ResetManager;
use VoltStack\Runtime\Reset\ResetReport;

final class ManagedExceptionRuntimeBridge implements ExceptionRuntimeBridge
{
    /**
     * @var array<string, array{operation: RuntimeOperation, finalized: bool}>
     */
    private array $activeScopes = [];

    /**
     * @var array<string, ResetReport>
     */
    private array $closedReports = [];

    public function __construct(
        private readonly Application $app,
        private readonly ExceptionScopeLifecycleManager $lifecycleManager,
        private readonly ResetManager $resetManager,
    ) {
    }

    public function begin(RuntimeOperation $operation): ExceptionScope
    {
        $runtimeContext = RuntimeContext::current();
        $scope = $this->lifecycleManager->start($runtimeContext);
        $scope->set('runtime.operation_id', $operation->operationId);
        $scope->set('runtime.transport', $operation->transport);
        $scope->set('runtime.deadline_at', $operation->deadlineAt);
        $scope->set('runtime.metadata', $operation->metadata);

        $this->activeScopes[$scope->id()] = [
            'operation' => $operation,
            'finalized' => false,
        ];

        if ($runtimeContext !== null) {
            $runtimeContext->set('exceptions.operation_id', $operation->operationId);
            $runtimeContext->set('exceptions.transport', $operation->transport);
            $runtimeContext->set('exceptions.scope_finalized', false);
        }

        return $scope;
    }

    public function context(ExceptionScope $scope): ExceptionContext
    {
        $runtimeContext = RuntimeContext::current();
        $metadata = $this->activeScopes[$scope->id()] ?? null;
        $operation = $metadata['operation'] ?? null;

        return $scope->createContext(
            correlationId: $runtimeContext?->requestId(),
            attributes: array_filter([
                'surface' => $runtimeContext?->get('runtime.channel', 'http'),
                'transport_kind' => $operation instanceof RuntimeOperation ? $operation->transport : 'runtime',
                'runtime_operation_id' => $operation instanceof RuntimeOperation ? $operation->operationId : null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    public function finalize(ExceptionScope $scope, FinalizationOutcome $outcome): void
    {
        $state = $this->activeScopes[$scope->id()] ?? null;

        if ($state === null || $state['finalized'] === true) {
            return;
        }

        $runtimeContext = RuntimeContext::current();

        if ($runtimeContext !== null) {
            $runtimeContext->set('exceptions.finalization_outcome', $outcome->kind);
            $runtimeContext->set('exceptions.finalization_metadata', $outcome->metadata);
        }

        $this->lifecycleManager->end($scope, $runtimeContext);
        $this->activeScopes[$scope->id()]['finalized'] = true;
    }

    public function close(ExceptionScope $scope): ResetReport
    {
        $scopeId = $scope->id();

        if (isset($this->closedReports[$scopeId])) {
            return $this->closedReports[$scopeId];
        }

        if (($this->activeScopes[$scopeId]['finalized'] ?? false) !== true) {
            $this->finalize($scope, FinalizationOutcome::failed([
                'reason' => 'implicit_close',
            ]));
        }

        $report = $this->resetManager->reset($this->app);
        unset($this->activeScopes[$scopeId]);

        $this->closedReports[$scopeId] = $report;

        if (count($this->closedReports) > 32) {
            array_shift($this->closedReports);
        }

        return $report;
    }
}
