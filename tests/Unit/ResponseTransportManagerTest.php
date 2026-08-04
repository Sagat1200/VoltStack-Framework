<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use Exception;
use PHPUnit\Framework\TestCase;
use Quantum\Transport\Adapters\HttpTransportAdapter;
use Quantum\Transport\Contracts\PreparedTransportResponseInterface;
use Quantum\Transport\Contracts\ResponseInterface;
use Quantum\Transport\Contracts\TransportAdapterInterface;
use Quantum\Transport\Enums\TransportStatus;
use Quantum\Transport\Response\ResponseMetadata;
use Quantum\Transport\Response\TransportResponse;
use Quantum\Transport\ResponseBody\TextResponseBody;
use Quantum\Transport\ResponseTransportManager;
use Quantum\Transport\Runtime\TransportContext;
use Quantum\Transport\Testing\InMemoryTransportEmitter;

final class ResponseTransportManagerTest extends TestCase
{
    public function test_it_prepares_and_emits_http_transport(): void
    {
        $response = (new TransportResponse())
            ->withStatus(201)
            ->withMetadata((new ResponseMetadata())->header('X-Test', 'ok'))
            ->withBody(new TextResponseBody('hi'));

        $emitter = new InMemoryTransportEmitter();
        $manager = new ResponseTransportManager(new HttpTransportAdapter(), $emitter);

        $result = $manager->send($response, new TransportContext());

        self::assertSame(TransportStatus::Completed, $result->status);
        self::assertSame(2, $result->bytesEmitted);
        self::assertTrue($result->completed);
        self::assertFalse($result->connectionClosed);

        $emitted = $emitter->emitted();
        self::assertCount(1, $emitted);

        $prepared = $emitted[0]['response'];
        self::assertInstanceOf(PreparedTransportResponseInterface::class, $prepared);
        self::assertSame('http', $prepared->transportType());
        self::assertSame('hi', $prepared->payload());
        self::assertSame(201, $prepared->metadata()->status);
        self::assertSame(['X-Test' => 'ok'], $prepared->metadata()->headers);
        self::assertFalse($prepared->isStreaming());
    }

    public function test_it_returns_failed_transport_result_when_adapter_throws(): void
    {
        $adapter = new class implements TransportAdapterInterface {
            public function type(): string
            {
                return 'http';
            }

            public function supports(ResponseInterface $response, TransportContext $context): bool
            {
                return true;
            }

            public function prepare(ResponseInterface $response, TransportContext $context): PreparedTransportResponseInterface
            {
                throw new Exception('boom');
            }
        };

        $response = (new TransportResponse())->withBody(new TextResponseBody('hi'));
        $manager = new ResponseTransportManager($adapter, new InMemoryTransportEmitter());

        $result = $manager->send($response, new TransportContext());

        self::assertSame(TransportStatus::Failed, $result->status);
        self::assertFalse($result->completed);
        self::assertInstanceOf(Exception::class, $result->exception);
        self::assertSame('boom', $result->exception->getMessage());
    }
}

