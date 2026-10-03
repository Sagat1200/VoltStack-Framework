<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Adapters;

use Quantum\Bootstrap\ApplicationPlan;
use Quantum\Http\Request;
use Quantum\Exceptions\Enums\WorkerDisposition;
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

        if ($requests === []) {
            return 0;
        }

        foreach ($requests as $index => $request) {
            if ($index >= $workerContext->maxRequests()) {
                break;
            }

            $result = $session->handle($request);

            if ($result->workerDisposition() === WorkerDisposition::Terminate) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * @return list<Request>
     */
    private function normalizeRequestSource(mixed $source): array
    {
        if ($source === null) {
            return [];
        }

        if (is_callable($source)) {
            $source = $source();
        }

        if ($source instanceof Traversable) {
            $source = iterator_to_array($source, false);
        }

        if (! is_array($source)) {
            throw new RuntimeAdapterException('FrankenPHP runtime request source must be iterable or callable.');
        }

        $requests = [];

        foreach ($source as $request) {
            if (! $request instanceof Request) {
                throw new RuntimeAdapterException('FrankenPHP runtime request source must yield Request instances.');
            }

            $requests[] = $request;
        }

        return $requests;
    }
}
