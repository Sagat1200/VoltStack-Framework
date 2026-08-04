<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use Exception;
use PHPUnit\Framework\TestCase;
use Quantum\Exceptions\Enums\ExceptionOrigin;
use Quantum\Exceptions\Enums\WorkerDisposition;
use Quantum\Exceptions\ExceptionHandler;
use Quantum\Exceptions\ExceptionHandlingContext;
use Quantum\Exceptions\Runtime\ExceptionHandlingState;
use Quantum\Exceptions\Runtime\RuntimeContext;
use Quantum\Metadata\MetadataBag;
use Quantum\Transport\Response\TransportResponse;
use Quantum\Transport\Runtime\TransportContext;
use Quantum\Transport\Runtime\TransportExecution;

final class QuantumExceptionHandlerTest extends TestCase
{
    public function test_it_aborts_when_transport_emission_has_started(): void
    {
        $handler = new ExceptionHandler();
        $throwable = new Exception('boom');

        $transportExecution = new TransportExecution(
            response: new TransportResponse(),
            context: new TransportContext(),
        );
        $transportExecution->emissionStarted = true;

        $context = new ExceptionHandlingContext(
            throwable: $throwable,
            origin: ExceptionOrigin::TransportEmission,
            runtime: new RuntimeContext(environment: 'local'),
            request: null,
            controllerExecution: null,
            transportExecution: $transportExecution,
            metadata: new MetadataBag([]),
            state: new ExceptionHandlingState(),
            debug: true,
        );

        $result = $handler->handle($throwable, $context);

        self::assertNull($result->response);
        self::assertSame(WorkerDisposition::Terminate, $result->workerDisposition);
        self::assertTrue($result->emissionStarted);
    }
}

