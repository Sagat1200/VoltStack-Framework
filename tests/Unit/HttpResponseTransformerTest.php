<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Http\Response;
use Quantum\Transport\Bridges\Http\HttpResponseTransformer;
use Quantum\Transport\ResponseBody\TextResponseBody;

final class HttpResponseTransformerTest extends TestCase
{
    public function test_it_transforms_quantum_http_response_to_transport_response(): void
    {
        $http = (new Response('hi'))
            ->setStatusCode(201)
            ->header('X-Test', 'ok');

        $transport = (new HttpResponseTransformer())->transform($http);

        self::assertSame(201, $transport->status());
        self::assertSame(['X-Test' => 'ok'], $transport->metadata()->headers);
        self::assertInstanceOf(TextResponseBody::class, $transport->body());
        self::assertSame('hi', $transport->body()->content);
    }
}

