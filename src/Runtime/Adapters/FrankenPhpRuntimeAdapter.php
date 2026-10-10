<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Adapters;

use Closure;
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
use VoltStack\Runtime\SequentialRequestLoop;
use VoltStack\Runtime\WorkerSession;

final class FrankenPhpRuntimeAdapter implements RuntimeAdapterInterface
{
    public function __construct(
        private readonly ?Closure $nativeLoopInvoker = null,
        private readonly ?Closure $nativeAvailabilityResolver = null,
    ) {
    }

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
            evidenceLevel: 'contractual',
            nativeIntegrationVerified: false,
            evidenceNotes: [
                'El adapter ya implementa loop nativo y contratos operativos, pero aun no esta contrastado contra FrankenPHP real en esta plataforma.',
            ],
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
        $app = $session->app();
        $emitter = new RuntimeAdapterResponseEmitter(
            transformer: $app->make(HttpResponseTransformer::class),
            transport: $app->make(ResponseTransportManagerInterface::class),
        );
        $requestSource = $configuration->requestSource();

        if ($requestSource === null) {
            return $this->runNativeLoop($session, $emitter);
        }

        $requests = SequentialRequestLoop::normalizeRequests($requestSource, 'FrankenPHP');
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

    private function runNativeLoop(WorkerSession $session, RuntimeAdapterResponseEmitter $emitter): int
    {
        if (! $this->isNativeRuntimeAvailable()) {
            throw new RuntimeAdapterException(
                'FrankenPHP native worker mode requires frankenphp_handle_request() when no in-memory request source is provided.'
            );
        }

        while ($session->canAcceptMoreRequests()) {
            $continue = $this->invokeNativeLoop(function () use ($session, $emitter): void {
                $request = Request::capture();
                $result = $session->handle($request);

                if ($result->workerDisposition() === WorkerDisposition::Terminate) {
                    return;
                }

                if (! $emitter->emit($session->context(), $session->handledRequests(), $result->response(), $request)) {
                    $session->lifecycle()->request(WorkerDisposition::Terminate);
                }
            });

            if ($session->lifecycle()->shouldTerminate()) {
                return 1;
            }

            if (! $continue) {
                break;
            }
        }

        return 0;
    }

    private function isNativeRuntimeAvailable(): bool
    {
        if ($this->nativeAvailabilityResolver !== null) {
            return (bool) ($this->nativeAvailabilityResolver)();
        }

        return function_exists('frankenphp_handle_request');
    }

    private function invokeNativeLoop(callable $handler): bool
    {
        if ($this->nativeLoopInvoker !== null) {
            return (bool) ($this->nativeLoopInvoker)($handler);
        }

        /** @phpstan-ignore-next-line */
        return call_user_func('frankenphp_handle_request', $handler);
    }
}
