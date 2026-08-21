<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Transport\Bridges\Http\HttpKernelTransportKernel;
use Quantum\Transport\Bridges\Http\HttpResponseTransformer;
use Quantum\Transport\Contracts\ResponseInterface;
use Quantum\Transport\ResponseBody\TextResponseBody;
use VoltStack\Framework\Contracts\Kernel;

final class HttpKernelTransportKernelTest extends TestCase
{
    public function test_it_returns_transport_response_via_kernel_and_transformer(): void
    {
        $request = Request::create('/hello', 'GET');
        $httpResponse = (new Response('ok'))->setStatusCode(200)->header('X-Foo', 'bar');

        $kernel = $this->createMock(Kernel::class);
        $kernel->expects(self::once())
            ->method('handle')
            ->with(self::identicalTo($request))
            ->willReturn($httpResponse);

        $bridge = new HttpKernelTransportKernel($kernel, new HttpResponseTransformer());
        $transportResponse = $bridge->handle($request);

        self::assertInstanceOf(ResponseInterface::class, $transportResponse);
        self::assertSame(200, $transportResponse->status());
        self::assertSame(['X-Foo' => 'bar'], $transportResponse->metadata()->headers);
        self::assertInstanceOf(TextResponseBody::class, $transportResponse->body());
        self::assertSame('ok', $transportResponse->body()->content);
    }

    public function test_it_preserves_status_and_body_from_kernel_error_response(): void
    {
        $request = Request::create('/missing', 'GET');
        $httpResponse = (new Response('not found', 404))->header('Content-Type', 'text/plain');

        $kernel = $this->createStub(Kernel::class);
        $kernel->method('handle')->willReturn($httpResponse);

        $bridge = new HttpKernelTransportKernel($kernel, new HttpResponseTransformer());
        $transportResponse = $bridge->handle($request);

        self::assertSame(404, $transportResponse->status());
        self::assertSame(['Content-Type' => 'text/plain'], $transportResponse->metadata()->headers);
        self::assertSame('not found', $transportResponse->body()->content);
    }
}
