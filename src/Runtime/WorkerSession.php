<?php

declare(strict_types=1);

namespace VoltStack\Runtime;

use Quantum\Bootstrap\BootstrapResult;
use Quantum\Http\Request;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\WorkerContext;
use VoltStack\Runtime\Context\WorkerLifecycle;

final class WorkerSession
{
    private int $handledRequests = 0;

    public function __construct(
        private readonly Application $app,
        private readonly BootstrapResult $bootstrapResult,
        private readonly RequestRunner $requestRunner,
        private readonly WorkerLifecycle $workerLifecycle,
        private readonly WorkerContext $context,
    ) {
    }

    public function app(): Application
    {
        return $this->app;
    }

    public function bootstrapResult(): BootstrapResult
    {
        return $this->bootstrapResult;
    }

    public function lifecycle(): WorkerLifecycle
    {
        return $this->workerLifecycle;
    }

    public function context(): WorkerContext
    {
        return $this->context;
    }

    public function handledRequests(): int
    {
        return $this->handledRequests;
    }

    public function canAcceptMoreRequests(): bool
    {
        return $this->handledRequests < $this->context->maxRequests()
            && ! $this->workerLifecycle->shouldTerminate();
    }

    public function handle(Request $request): RequestRunResult
    {
        $result = $this->requestRunner->run($request);
        $this->handledRequests++;

        return $result;
    }
}
