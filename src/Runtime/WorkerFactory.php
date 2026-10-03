<?php

declare(strict_types=1);

namespace VoltStack\Runtime;

use Quantum\Bootstrap\ApplicationPlan;
use Quantum\Bootstrap\Context\BootstrapContext;
use Quantum\Bootstrap\Contracts\BootstrapperInterface;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\WorkerContext;
use VoltStack\Runtime\Context\WorkerLifecycle;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;

final class WorkerFactory implements WorkerFactoryInterface
{
    public function __construct(private readonly Application $app)
    {
    }

    public function create(ApplicationPlan $plan, WorkerContext $context): WorkerSession
    {
        /** @var BootstrapperInterface $bootstrapper */
        $bootstrapper = $this->app->make(BootstrapperInterface::class);

        $bootstrapContext = BootstrapContext::forConsole(
            environment: $plan->environment() ?? 'local',
            artifactPolicy: 'prefer-artifacts',
            executionMode: 'worker',
            profile: $plan->profile(),
        );

        $result = $bootstrapper->bootPlan($plan, $bootstrapContext);

        return new WorkerSession(
            app: $this->app,
            bootstrapResult: $result,
            requestRunner: $this->app->make(RequestRunner::class),
            workerLifecycle: $this->app->make(WorkerLifecycle::class),
            context: $context,
        );
    }
}
