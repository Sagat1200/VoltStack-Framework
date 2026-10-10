<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Adapters;

use Quantum\Bootstrap\ApplicationPlan;
use VoltStack\Runtime\Contracts\RuntimeAdapterInterface;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;
use VoltStack\Runtime\RuntimeCapabilities;
use VoltStack\Runtime\RuntimeConfiguration;
use VoltStack\Runtime\SequentialRequestLoop;

final class RoadRunnerRuntimeAdapter implements RuntimeAdapterInterface
{
    public function id(): string
    {
        return 'roadrunner';
    }

    public function capabilities(): RuntimeCapabilities
    {
        return new RuntimeCapabilities(
            persistent: true,
            concurrent: false,
            streaming: false,
            drainControl: true,
            nativeHttp: false,
            evidenceLevel: 'simulated',
            nativeIntegrationVerified: false,
            evidenceNotes: [
                'El adapter depende de requestSource en memoria hasta implementar un bridge HTTP nativo de RoadRunner.',
            ],
        );
    }

    public function run(
        ApplicationPlan $plan,
        WorkerFactoryInterface $factory,
        RuntimeConfiguration $configuration,
    ): int {
        return SequentialRequestLoop::runForSourceDriver(
            driver: $this->id(),
            plan: $plan,
            factory: $factory,
            configuration: $configuration,
            driverLabel: 'RoadRunner',
        );
    }
}
