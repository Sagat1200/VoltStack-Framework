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
        $resetReport = new ResetReport(0, true);
        $scope = null;
        $scopeEnded = false;
        $outcome = FinalizationOutcome::completed();

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

            try {
                $this->scopeManager->end();
                $scopeEnded = true;
            } catch (Throwable $secondary) {
                if ($exception === null) {
                    $exception = $secondary;
                    $this->workerLifecycle->request(WorkerDisposition::Terminate);
                }
            }

            if ($scope !== null) {
                try {
                    $resetReport = $this->exceptionRuntimeBridge->close($scope);
                } catch (Throwable $secondary) {
                    $resetReport = new ResetReport(0, false, [$secondary]);

                    if ($exception === null) {
                        $exception = $secondary;
                    }

                    $this->workerLifecycle->request(WorkerDisposition::Terminate);
                }
            } elseif (! $scopeEnded) {
                $resetReport = new ResetReport(0, false, [
                    new \RuntimeException('Runtime request scope was not initialized.'),
                ]);
                $this->workerLifecycle->request(WorkerDisposition::Terminate);
            }
        }

        $requestedDisposition = $this->resolveRequestedDisposition();

        if (! $resetReport->successful()) {
            $this->workerLifecycle->request(WorkerDisposition::Terminate);
            $requestedDisposition = WorkerDisposition::Terminate;
        }

        if ($requestedDisposition === WorkerDisposition::Reset && $resetReport->successful()) {
            $this->workerLifecycle->clearResetRequest();
        }

        return new RequestRunResult(
            request: $request,
            response: $this->normalizeResponse($response),
            workerDisposition: $requestedDisposition,
            resetReport: $resetReport,
            durationMs: (microtime(true) - $startedAt) * 1000,
            exception: $exception,
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
