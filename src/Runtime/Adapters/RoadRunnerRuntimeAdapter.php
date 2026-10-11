<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Adapters;

use Quantum\Bootstrap\ApplicationPlan;
use Quantum\Exceptions\Enums\WorkerDisposition;
use VoltStack\Runtime\Context\WorkerContext;
use VoltStack\Runtime\Contracts\RuntimeAdapterInterface;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;
use VoltStack\Runtime\Exceptions\RuntimeAdapterException;
use VoltStack\Runtime\RuntimeCapabilities;
use VoltStack\Runtime\RuntimeConfiguration;
use VoltStack\Runtime\SequentialRequestLoop;

final class RoadRunnerRuntimeAdapter implements RuntimeAdapterInterface
{
    public function __construct(
        private readonly ?RoadRunnerNativeWorkerInterface $nativeWorker = null,
    ) {
    }

    public function id(): string
    {
        return 'roadrunner';
    }

    public function capabilities(): RuntimeCapabilities
    {
        $hasNativeWorker = $this->nativeWorker !== null;

        return new RuntimeCapabilities(
            persistent: true,
            concurrent: false,
            streaming: false,
            drainControl: true,
            nativeHttp: $hasNativeWorker,
            evidenceLevel: $hasNativeWorker ? 'native-verified' : 'simulated',
            nativeIntegrationVerified: $hasNativeWorker,
            evidenceNotes: $hasNativeWorker
                ? ['RoadRunner native HTTP bridge activo via RoadRunnerNativeWorkerInterface inyectado.']
                : ['El adapter depende de requestSource en memoria hasta inyectar un worker nativo de RoadRunner.'],
        );
    }

    public function run(
        ApplicationPlan $plan,
        WorkerFactoryInterface $factory,
        RuntimeConfiguration $configuration,
    ): int {
        if ($configuration->requestSource() !== null) {
            return SequentialRequestLoop::runForSourceDriver(
                driver: $this->id(),
                plan: $plan,
                factory: $factory,
                configuration: $configuration,
                driverLabel: 'RoadRunner',
            );
        }

        if ($this->nativeWorker === null) {
            throw new RuntimeAdapterException(
                'RoadRunner runtime adapter requires either an in-memory request source or an injected RoadRunnerNativeWorkerInterface.'
            );
        }

        return $this->runNativeLoop($plan, $factory, $configuration);
    }

    private function runNativeLoop(
        ApplicationPlan $plan,
        WorkerFactoryInterface $factory,
        RuntimeConfiguration $configuration,
    ): int {
        $session = $factory->create($plan, WorkerContext::create(
            driver: $this->id(),
            maxRequests: $configuration->maxRequests(),
        ));

        while ($session->canAcceptMoreRequests()) {
            $request = $this->nativeWorker->waitRequest();

            if ($request === null) {
                break;
            }

            $result = $session->handle($request);

            try {
                if ($result->workerDisposition() === WorkerDisposition::Terminate) {
                    return 1;
                }

                $response = $result->response();
                if ($response !== null) {
                    $this->nativeWorker->respond($response);
                }
            } finally {
                $result->finalizeScope();
            }
        }

        return 0;
    }
}
