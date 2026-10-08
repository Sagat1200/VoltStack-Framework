<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Adapters;

use Quantum\Bootstrap\ApplicationPlan;
use Quantum\Http\Request;
use Quantum\Exceptions\Enums\WorkerDisposition;
use Quantum\Transport\Bridges\Http\HttpResponseTransformer;
use Quantum\Transport\Contracts\ResponseTransportManagerInterface;
use Quantum\Transport\Runtime\TransportContext;
use Iterator;
use Traversable;
use VoltStack\Runtime\Context\WorkerContext;
use VoltStack\Runtime\Contracts\RuntimeAdapterInterface;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;
use VoltStack\Runtime\Exceptions\RuntimeAdapterException;
use VoltStack\Runtime\RuntimeCapabilities;
use VoltStack\Runtime\RuntimeConfiguration;

final class FrankenPhpRuntimeAdapter implements RuntimeAdapterInterface
{
    public function id(): string
    {
        return 'frankenphp';
    }

    public function capabilities(): RuntimeCapabilities
    {
        return new RuntimeCapabilities(
            persistent: true,
            concurrent: false,
            streaming: false,
            drainControl: true,
            nativeHttp: true,
        );
    }

    public function run(
        ApplicationPlan $plan,
        WorkerFactoryInterface $factory,
        RuntimeConfiguration $configuration,
    ): int {
        $workerContext = WorkerContext::create(
            driver: $this->id(),
            maxRequests: $configuration->maxRequests(),
        );

        $session = $factory->create($plan, $workerContext);
        $requests = $this->normalizeRequestSource($configuration->requestSource());
        $requests->rewind();

        while ($session->canAcceptMoreRequests() && $requests->valid()) {
            $request = $requests->current();
            $result = $session->handle($request);

            if ($result->workerDisposition() === WorkerDisposition::Terminate) {
                return 1;
            }

            if (! $this->emitResponse($session, $result->response(), $request)) {
                $session->lifecycle()->request(WorkerDisposition::Terminate);

                return 1;
            }

            if (! $session->canAcceptMoreRequests()) {
                break;
            }

            $requests->next();
        }

        return 0;
    }

    private function emitResponse(\VoltStack\Runtime\WorkerSession $session, ?\Quantum\Http\Response $response, Request $request): bool
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

    /**
     * @return Iterator<int, Request>
     */
    private function normalizeRequestSource(mixed $source): Iterator
    {
        if ($source === null) {
            return $this->yieldRequests([]);
        }

        if (is_callable($source)) {
            $source = $source();
        }

        if (! is_array($source) && ! $source instanceof Traversable) {
            throw new RuntimeAdapterException('FrankenPHP runtime request source must be iterable or callable.');
        }

        return $this->yieldRequests($source);
    }

    /**
     * @param iterable<mixed> $source
     * @return Iterator<int, Request>
     */
    private function yieldRequests(iterable $source): Iterator
    {
        foreach ($source as $request) {
            if (! $request instanceof Request) {
                throw new RuntimeAdapterException('FrankenPHP runtime request source must yield Request instances.');
            }

            yield $request;
        }
    }
}
