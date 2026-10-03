<?php

declare(strict_types=1);

namespace VoltStack\Runtime;

use Quantum\Exceptions\Enums\WorkerDisposition;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Throwable;
use VoltStack\Framework\Application;
use VoltStack\Framework\Contracts\Kernel as KernelContract;
use VoltStack\Runtime\Context\WorkerLifecycle;
use VoltStack\Runtime\Reset\ResetManager;
use VoltStack\Runtime\Reset\ResetReport;

final class RequestRunner
{
    public function __construct(
        private readonly Application $app,
        private readonly KernelContract $kernel,
        private readonly ResetManager $resetManager,
        private readonly WorkerLifecycle $workerLifecycle,
    ) {
    }

    public function run(Request $request): RequestRunResult
    {
        $startedAt = microtime(true);
        $response = null;
        $exception = null;

        try {
            $response = $this->kernel->handle($request);
        } catch (Throwable $throwable) {
            $exception = $throwable;
            $this->workerLifecycle->request(WorkerDisposition::Terminate);
        }

        $requestedDisposition = $this->resolveRequestedDisposition();
        $resetReport = $this->resetManager->reset($this->app);

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
