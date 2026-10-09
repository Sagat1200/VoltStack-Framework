<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Adapters;

use Quantum\Bootstrap\ApplicationPlan;
use Quantum\Exceptions\Enums\WorkerDisposition;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Transport\Bridges\Http\HttpResponseTransformer;
use Quantum\Transport\Contracts\ResponseTransportManagerInterface;
use Quantum\Transport\Runtime\TransportContext;
use VoltStack\Runtime\Context\WorkerContext;
use VoltStack\Runtime\Contracts\RuntimeAdapterInterface;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;
use VoltStack\Runtime\Exceptions\RuntimeAdapterException;
use VoltStack\Runtime\RuntimeCapabilities;
use VoltStack\Runtime\RuntimeConfiguration;
use VoltStack\Runtime\WorkerSession;

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
        $request = Request::capture();
        $result = $session->handle($request);

        if ($result->workerDisposition() === WorkerDisposition::Terminate) {
            return 1;
        }

        if (! $this->emitResponse($session, $result->response(), $request)) {
            $session->lifecycle()->request(WorkerDisposition::Terminate);

            return 1;
        }

        return 0;
    }

    private function emitResponse(WorkerSession $session, ?Response $response, Request $request): bool
    {
        if ($response === null) {
            return true;
        }

        /** @var HttpResponseTransformer $transformer */
        $transformer = $session->app()->make(HttpResponseTransformer::class);
        /** @var ResponseTransportManagerInterface $manager */
        $manager = $session->app()->make(ResponseTransportManagerInterface::class);

        $transportResponse = $transformer->transform($response);
        $transportContext = new TransportContext(
            request: $request,
            attributes: [
                'runtime.driver' => $session->context()->driver(),
                'runtime.worker_id' => $session->context()->workerId(),
                'runtime.handled_requests' => $session->handledRequests(),
            ],
        );
        $transportResult = $manager->send($transportResponse, $transportContext);

        return $transportResult->completed && $transportResult->exception === null;
    }
}
