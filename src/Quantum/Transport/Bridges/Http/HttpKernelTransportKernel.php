<?php

declare(strict_types=1);

namespace Quantum\Transport\Bridges\Http;

use Quantum\Http\Request;
use Quantum\Transport\Contracts\ResponseInterface;
use Quantum\Transport\Contracts\TransportKernelInterface;
use VoltStack\Framework\Contracts\Kernel as HttpKernelContract;

final readonly class HttpKernelTransportKernel implements TransportKernelInterface
{
    public function __construct(
        private HttpKernelContract $kernel,
        private HttpResponseTransformer $transformer,
    ) {
    }

    public function handle(Request $request): ResponseInterface
    {
        $response = $this->kernel->handle($request);

        return $this->transformer->transform($response);
    }
}

