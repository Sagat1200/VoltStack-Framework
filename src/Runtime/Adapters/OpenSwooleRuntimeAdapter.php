<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Adapters;

use Quantum\Bootstrap\ApplicationPlan;
use Quantum\Exceptions\Enums\WorkerDisposition;
use Quantum\Http\Request;
use Quantum\Http\Response;
use VoltStack\Runtime\Context\WorkerContext;
use VoltStack\Runtime\Contracts\RuntimeAdapterInterface;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;
use VoltStack\Runtime\Exceptions\RuntimeAdapterException;
use VoltStack\Runtime\RuntimeCapabilities;
use VoltStack\Runtime\RuntimeConfiguration;
use VoltStack\Runtime\SequentialRequestLoop;

final class OpenSwooleRuntimeAdapter implements RuntimeAdapterInterface
{
    public function __construct(
        private readonly ?OpenSwooleNativeServerInterface $nativeServer = null,
    ) {
    }

    public function id(): string
    {
        return 'openswoole';
    }

    public function capabilities(): RuntimeCapabilities
    {
        $hasNativeServer = $this->nativeServer !== null;

        return new RuntimeCapabilities(
            persistent: true,
            concurrent: false,
            streaming: false,
            drainControl: true,
            nativeHttp: $hasNativeServer,
            evidenceLevel: $hasNativeServer ? 'native-verified' : 'simulated',
            nativeIntegrationVerified: $hasNativeServer,
            evidenceNotes: $hasNativeServer
                ? ['OpenSwoole native HTTP bridge activo via OpenSwooleNativeServerInterface inyectado (modo secuencial).']
                : [
                    'El adapter OpenSwoole actual solo habilita un perfil secuencial sin coroutine.',
                    'El adapter depende de requestSource en memoria hasta inyectar un servidor nativo de OpenSwoole.',
                ],
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
                driverLabel: 'OpenSwoole',
            );
        }

        if ($this->nativeServer === null) {
            throw new RuntimeAdapterException(
                'OpenSwoole runtime adapter requires either an in-memory request source or an injected OpenSwooleNativeServerInterface.'
            );
        }

        return $this->runNativeServer($plan, $factory, $configuration);
    }

    private function runNativeServer(
        ApplicationPlan $plan,
        WorkerFactoryInterface $factory,
        RuntimeConfiguration $configuration,
    ): int {
        $session = $factory->create($plan, WorkerContext::create(
            driver: $this->id(),
            maxRequests: $configuration->maxRequests(),
        ));

        $terminated = false;

        $this->nativeServer->start(function (Request $request) use ($session, &$terminated): ?Response {
            if (! $session->canAcceptMoreRequests()) {
                return null;
            }

            $result = $session->handle($request);

            try {
                if ($result->workerDisposition() === WorkerDisposition::Terminate) {
                    $terminated = true;

                    return null;
                }

                return $result->response();
            } finally {
                $result->finalizeScope();
            }
        });

        return $terminated ? 1 : 0;
    }
}
