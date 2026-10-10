<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Adapters;

use Quantum\Bootstrap\ApplicationPlan;
use VoltStack\Runtime\Contracts\RuntimeAdapterInterface;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;
use VoltStack\Runtime\RuntimeCapabilities;
use VoltStack\Runtime\RuntimeConfiguration;
use VoltStack\Runtime\SequentialRequestLoop;

final class OpenSwooleRuntimeAdapter implements RuntimeAdapterInterface
{
    public function id(): string
    {
        return 'openswoole';
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
                'El adapter OpenSwoole actual solo habilita un perfil secuencial sin coroutine.',
                'El adapter depende de requestSource en memoria hasta implementar un bridge HTTP nativo de OpenSwoole.',
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
            driverLabel: 'OpenSwoole',
        );
    }
}
