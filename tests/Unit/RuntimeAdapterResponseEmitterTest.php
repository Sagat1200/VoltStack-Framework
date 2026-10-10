<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Transport\Bridges\Http\HttpResponseTransformer;
use Quantum\Transport\Contracts\ResponseTransportManagerInterface;
use Quantum\Transport\Enums\TransportStatus;
use Quantum\Transport\Response\TransportResponse;
use Quantum\Transport\Runtime\TransportContext;
use Quantum\Transport\Runtime\TransportResult;
use RuntimeException;
use VoltStack\Runtime\Context\WorkerContext;
use VoltStack\Runtime\RuntimeAdapterResponseEmitter;

final class RuntimeAdapterResponseEmitterTest extends TestCase
{
    public function test_emitter_returns_true_when_response_is_null_without_invoking_transformer_or_transport(): void
    {
        $transformer = new HttpResponseTransformer();
        $transport = $this->createMock(ResponseTransportManagerInterface::class);
        $transport->expects(self::never())->method('send');

        $emitter = new RuntimeAdapterResponseEmitter($transformer, $transport);

        self::assertTrue($emitter->emit(
            WorkerContext::create('openswoole', 3),
            1,
            null,
            Request::create('/ping', 'GET'),
        ));
    }

    public function test_emitter_transforms_response_and_sends_transport_context_with_runtime_attributes(): void
    {
        $workerContext = WorkerContext::create('roadrunner', 5);
        $request = Request::create('/health', 'GET');
        $response = new Response('UP', 200, ['Content-Type' => 'text/plain']);

        $transport = $this->createMock(ResponseTransportManagerInterface::class);
        $transport
            ->expects(self::once())
            ->method('send')
            ->with(
                self::callback(static function (TransportResponse $transportResponse): bool {
                    self::assertSame(200, $transportResponse->status());
                    self::assertSame('text/plain', $transportResponse->metadata()->headers['Content-Type'] ?? null);
                    $body = $transportResponse->body();
                    self::assertTrue(property_exists($body, 'content'));
                    self::assertSame('UP', $body->content);

                    return true;
                }),
                self::callback(static function (TransportContext $context) use ($workerContext, $request): bool {
                    self::assertSame($request, $context->request);
                    $attributes = $context->attributes;
                    self::assertSame($workerContext->driver(), $attributes['runtime.driver'] ?? null);
                    self::assertSame($workerContext->workerId(), $attributes['runtime.worker_id'] ?? null);
                    self::assertSame(3, $attributes['runtime.handled_requests'] ?? null);

                    return true;
                }),
            )
            ->willReturn(new TransportResult(
                status: TransportStatus::Completed,
                bytesEmitted: 2,
                completed: true,
                connectionClosed: false,
                emissionStarted: true,
                exception: null,
            ));

        $emitter = new RuntimeAdapterResponseEmitter(new HttpResponseTransformer(), $transport);

        self::assertTrue($emitter->emit($workerContext, 3, $response, $request));
    }

    public function test_emitter_returns_false_when_transport_does_not_complete(): void
    {
        $workerContext = WorkerContext::create('frankenphp', 2);
        $request = Request::create('/partial', 'GET');
        $response = new Response('partial');
        $incompleteResult = new TransportResult(
            status: TransportStatus::Failed,
            bytesEmitted: 4,
            completed: false,
            connectionClosed: true,
            emissionStarted: true,
            exception: null,
        );

        $transport = $this->createMock(ResponseTransportManagerInterface::class);
        $transport->method('send')->willReturn($incompleteResult);

        $emitter = new RuntimeAdapterResponseEmitter(new HttpResponseTransformer(), $transport);

        self::assertFalse($emitter->emit($workerContext, 1, $response, $request));
    }

    public function test_emitter_returns_false_when_transport_captures_an_exception(): void
    {
        $workerContext = WorkerContext::create('roadrunner', 1);
        $request = Request::create('/boom', 'GET');
        $response = new Response('ignored', 200);
        $failedResult = new TransportResult(
            status: TransportStatus::Failed,
            bytesEmitted: 0,
            completed: true,
            connectionClosed: true,
            emissionStarted: false,
            exception: new RuntimeException('transport failure'),
        );

        $transport = $this->createMock(ResponseTransportManagerInterface::class);
        $transport->method('send')->willReturn($failedResult);

        $emitter = new RuntimeAdapterResponseEmitter(new HttpResponseTransformer(), $transport);

        self::assertFalse($emitter->emit($workerContext, 1, $response, $request));
    }
}
