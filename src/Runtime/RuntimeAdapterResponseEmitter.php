<?php

declare(strict_types=1);

namespace VoltStack\Runtime;

use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\Transport\Bridges\Http\HttpResponseTransformer;
use Quantum\Transport\Contracts\ResponseTransportManagerInterface;
use Quantum\Transport\Runtime\TransportContext;
use VoltStack\Runtime\Context\WorkerContext;

final class RuntimeAdapterResponseEmitter
{
    public function __construct(
        private readonly HttpResponseTransformer $transformer,
        private readonly ResponseTransportManagerInterface $transport,
    ) {
    }

    public function emit(
        WorkerContext $context,
        int $handledRequests,
        ?Response $response,
        Request $request,
    ): bool {
        if ($response === null) {
            return true;
        }

        $transportResponse = $this->transformer->transform($response);
        $transportContext = new TransportContext(
            request: $request,
            attributes: [
                'runtime.driver' => $context->driver(),
                'runtime.worker_id' => $context->workerId(),
                'runtime.handled_requests' => $handledRequests,
            ],
        );
        $transportResult = $this->transport->send($transportResponse, $transportContext);

        return $transportResult->completed && $transportResult->exception === null;
    }
}
