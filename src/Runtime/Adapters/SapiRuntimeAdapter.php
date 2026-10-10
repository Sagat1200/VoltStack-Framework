<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Adapters;

use Quantum\Bootstrap\ApplicationPlan;
use Quantum\Exceptions\Enums\WorkerDisposition;
use Quantum\Http\Request;
use Quantum\Transport\Bridges\Http\HttpResponseTransformer;
use Quantum\Transport\Contracts\ResponseTransportManagerInterface;
use VoltStack\Runtime\Context\WorkerContext;
use VoltStack\Runtime\Contracts\RuntimeAdapterInterface;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;
use VoltStack\Runtime\Exceptions\RuntimeAdapterException;
use VoltStack\Runtime\RuntimeAdapterResponseEmitter;
use VoltStack\Runtime\RuntimeCapabilities;
use VoltStack\Runtime\RuntimeConfiguration;

final class SapiRuntimeAdapter implements RuntimeAdapterInterface
{
    public function id(): string
    {
        return 'sapi';
    }

    public function capabilities(): RuntimeCapabilities
    {
        return new RuntimeCapabilities(
            persistent: false,
            concurrent: false,
            streaming: false,
            drainControl: false,
            nativeHttp: true,
            evidenceLevel: 'native-verified',
            nativeIntegrationVerified: true,
            evidenceNotes: [
                'El adapter SAPI usa la captura/respuesta nativa del runtime PHP actual.',
            ],
        );
    }

    public function run(
        ApplicationPlan $plan,
        WorkerFactoryInterface $factory,
        RuntimeConfiguration $configuration,
    ): int {
        if ($configuration->maxRequests() !== 1) {
            throw new RuntimeAdapterException('SAPI runtime adapter only supports maxRequests=1.');
        }

        if ($configuration->requestSource() !== null) {
            throw new RuntimeAdapterException('SAPI runtime adapter does not accept an in-memory request source.');
        }

        $session = $factory->create($plan, WorkerContext::create(
            driver: $this->id(),
            maxRequests: 1,
        ));
        $app = $session->app();
        $emitter = new RuntimeAdapterResponseEmitter(
            transformer: $app->make(HttpResponseTransformer::class),
            transport: $app->make(ResponseTransportManagerInterface::class),
        );
        $request = Request::capture();
        $result = $session->handle($request);

        if ($result->workerDisposition() === WorkerDisposition::Terminate) {
            return 1;
        }

        if (! $emitter->emit($session->context(), $session->handledRequests(), $result->response(), $request)) {
            $session->lifecycle()->request(WorkerDisposition::Terminate);

            return 1;
        }

        return 0;
    }
}
