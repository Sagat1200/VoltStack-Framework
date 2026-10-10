<?php

declare(strict_types=1);

namespace VoltStack\Runtime;

use Iterator;
use Quantum\Bootstrap\ApplicationPlan;
use Quantum\Exceptions\Enums\WorkerDisposition;
use Quantum\Transport\Bridges\Http\HttpResponseTransformer;
use Quantum\Transport\Contracts\ResponseTransportManagerInterface;
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;
use VoltStack\Runtime\Context\WorkerContext;
use VoltStack\Runtime\Exceptions\RuntimeAdapterException;

final class SequentialRequestLoop
{
    /**
     * Run a sequential harness-based request loop for a persistent adapter that
     * requires an in-memory request source.
     *
     * @param non-empty-string $driver
     * @param non-empty-string $driverLabel Human-readable label used in exception messages.
     */
    public static function runForSourceDriver(
        string $driver,
        ApplicationPlan $plan,
        WorkerFactoryInterface $factory,
        RuntimeConfiguration $configuration,
        string $driverLabel,
    ): int {
        $requestSource = $configuration->requestSource();

        if ($requestSource === null) {
            throw new RuntimeAdapterException(sprintf(
                '%s runtime adapter requires an in-memory request source until a native HTTP bridge is implemented.',
                $driverLabel,
            ));
        }

        $session = $factory->create($plan, WorkerContext::create(
            driver: $driver,
            maxRequests: $configuration->maxRequests(),
        ));
        $app = $session->app();
        $emitter = new RuntimeAdapterResponseEmitter(
            transformer: $app->make(HttpResponseTransformer::class),
            transport: $app->make(ResponseTransportManagerInterface::class),
        );
        $requests = RuntimeRequestSourceNormalizer::normalize($requestSource, $driverLabel);
        $requests->rewind();

        while ($session->canAcceptMoreRequests() && $requests->valid()) {
            $request = $requests->current();
            $result = $session->handle($request);

            if ($result->workerDisposition() === WorkerDisposition::Terminate) {
                return 1;
            }

            if (! $emitter->emit($session->context(), $session->handledRequests(), $result->response(), $request)) {
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

    /**
     * @return Iterator<int, \Quantum\Http\Request>
     */
    public static function normalizeRequests(mixed $source, string $driverLabel): Iterator
    {
        return RuntimeRequestSourceNormalizer::normalize($source, $driverLabel);
    }
}
