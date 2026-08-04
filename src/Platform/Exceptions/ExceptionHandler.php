<?php

declare(strict_types=1);

namespace VoltStack\Framework\Exceptions;

use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Exceptions\Contracts\ExceptionHandlerInterface;
use Quantum\Exceptions\Enums\ExceptionOrigin;
use Quantum\Exceptions\ExceptionHandlingContext;
use Quantum\Exceptions\Runtime\ExceptionHandlingState;
use Quantum\Exceptions\Runtime\RuntimeContext;
use Quantum\Metadata\MetadataBag;
use Throwable;
use VoltStack\Framework\Contracts\ExceptionHandler as ExceptionHandlerContract;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\WorkerLifecycle;

final class ExceptionHandler implements ExceptionHandlerContract
{
    public function __construct(
        private readonly ExceptionHandlerInterface $handler,
        private readonly Application $app,
    ) {}

    public function render(Request $request, Throwable $exception): Response
    {
        $context = new ExceptionHandlingContext(
            throwable: $exception,
            origin: ExceptionOrigin::Routing,
            runtime: new RuntimeContext(environment: $this->app->environment()),
            request: $request,
            controllerExecution: null,
            transportExecution: null,
            metadata: new MetadataBag([]),
            state: new ExceptionHandlingState(),
            debug: $this->app->isDevelopment(),
        );

        $result = $this->handler->handle($exception, $context);

        $this->app->make(WorkerLifecycle::class)->request($result->workerDisposition);

        if ($result->response instanceof Response) {
            return $result->response;
        }

        return new Response('', 500, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
