<?php

declare(strict_types=1);

namespace VoltStack\Runtime;

use Quantum\Exceptions\Bridges\Runtime\ExceptionRuntimeBridge;
use Quantum\Exceptions\Bridges\Runtime\FinalizationOutcome;
use Quantum\Exceptions\Bridges\Runtime\RuntimeOperation;
use Quantum\Exceptions\Enums\WorkerDisposition;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Throwable;
use VoltStack\Framework\Application;
use VoltStack\Framework\Contracts\Kernel as KernelContract;
use VoltStack\Runtime\Context\ScopeManager;
use VoltStack\Runtime\Context\WorkerLifecycle;
use VoltStack\Runtime\Reset\ResetReport;

final class RequestRunner
{
    public function __construct(
        private readonly Application $app,
        private readonly KernelContract $kernel,
        private readonly ScopeManager $scopeManager,
        private readonly ExceptionRuntimeBridge $exceptionRuntimeBridge,
        private readonly WorkerLifecycle $workerLifecycle,
    ) {
    }

    public function run(Request $request): RequestRunResult
    {
        $startedAt = microtime(true);
        $response = null;
        $exception = null;
        $scope = null;
        $outcome = FinalizationOutcome::completed();

        // Holder mutable para estado post-finalización. El finalizador (que se
        // ejecuta después de emitir la respuesta) actualiza resetReport y
        // disposition. RequestRunResult lee de este holder.
        $scopeState = new class {
            public ResetReport $resetReport;
            public WorkerDisposition $disposition;
        };
        $scopeState->resetReport = new ResetReport(0, true);
        $scopeState->disposition = WorkerDisposition::Reuse;

        try {
            $runtimeContext = $this->scopeManager->begin($request);
            $scope = $this->exceptionRuntimeBridge->begin(new RuntimeOperation(
                transport: 'http',
                operationId: $runtimeContext->requestId(),
                metadata: [
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'runtime_scope_kind' => $runtimeContext->get('runtime.scope_kind'),
                ],
            ));
            $response = $this->kernel->handle($request);
        } catch (Throwable $throwable) {
            $exception = $throwable;
            $outcome = FinalizationOutcome::failed([
                'exception_class' => $throwable::class,
            ]);
            $this->workerLifecycle->request(WorkerDisposition::Terminate);
        } finally {
            // finalize() sólo marca el outcome del scope de excepciones; no
            // cierra el RuntimeContext ni ejecuta reset. Es seguro invocarlo
            // antes de emitir la respuesta.
            if ($scope !== null) {
                try {
                    $this->exceptionRuntimeBridge->finalize($scope, $outcome);
                } catch (Throwable $secondary) {
                    if ($exception === null) {
                        $exception = $secondary;
                        $outcome = FinalizationOutcome::failed([
                            'exception_class' => $secondary::class,
                            'reason' => 'bridge_finalize_failed',
                        ]);
                        $this->workerLifecycle->request(WorkerDisposition::Terminate);
                    }
                }
            }
        }

        // Disposición previa a la emisión: basada en la presencia de excepción
        // (workerLifecycle), no en el resultado del reset (que aún no corre).
        $scopeState->disposition = $this->resolveRequestedDisposition();

        $scopeFinalizer = function () use ($scope, $scopeState, &$exception): void {
            if ($scope === null) {
                $scopeState->resetReport = new ResetReport(0, false, [
                    new \RuntimeException('Runtime request scope was not initialized.'),
                ]);
                $scopeState->disposition = WorkerDisposition::Terminate;

                return;
            }

            try {
                $this->scopeManager->end();
            } catch (Throwable $secondary) {
                if ($exception === null) {
                    $exception = $secondary;
                }

                $this->workerLifecycle->request(WorkerDisposition::Terminate);
            }

            try {
                $scopeState->resetReport = $this->exceptionRuntimeBridge->close($scope);
            } catch (Throwable $secondary) {
                $scopeState->resetReport = new ResetReport(0, false, [$secondary]);

                if ($exception === null) {
                    $exception = $secondary;
                }

                $this->workerLifecycle->request(WorkerDisposition::Terminate);
            }

            // Disposición post-reset: si el reset falló, terminar el worker.
            if (! $scopeState->resetReport->successful()) {
                $scopeState->disposition = WorkerDisposition::Terminate;
            } elseif ($scopeState->disposition === WorkerDisposition::Reset) {
                $this->workerLifecycle->clearResetRequest();
            }
        };

        return new RequestRunResult(
            request: $request,
            response: $this->normalizeResponse($response),
            workerDisposition: $scopeState->disposition,
            resetReport: $scopeState->resetReport,
            durationMs: (microtime(true) - $startedAt) * 1000,
            exception: $exception,
            scopeFinalizer: $scopeFinalizer,
            scopeState: $scopeState,
        );
    }

    private function resolveRequestedDisposition(): WorkerDisposition
    {
        if ($this->workerLifecycle->shouldTerminate()) {
            return WorkerDisposition::Terminate;
        }

        if ($this->workerLifecycle->shouldReset()) {
            return WorkerDisposition::Reset;
        }

        return WorkerDisposition::Reuse;
    }

    private function normalizeResponse(?Response $response): ?Response
    {
        return $response;
    }
}
