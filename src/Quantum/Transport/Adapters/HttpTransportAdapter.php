<?php

declare(strict_types=1);

namespace Quantum\Transport\Adapters;

use InvalidArgumentException;
use Quantum\Transport\Contracts\PreparedTransportResponseInterface;
use Quantum\Transport\Contracts\ResponseInterface;
use Quantum\Transport\Contracts\TransportAdapterInterface;
use Quantum\Transport\Enums\ResponseBodyType;
use Quantum\Transport\ResponseBody\TextResponseBody;
use Quantum\Transport\Runtime\PreparedTransportResponse;
use Quantum\Transport\Runtime\TransportContext;
use Quantum\Transport\Runtime\TransportEmissionMetadata;

final class HttpTransportAdapter implements TransportAdapterInterface
{
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
        $body = $response->body();

        $payload = '';

        if ($body->type() === ResponseBodyType::Text) {
            if (! $body instanceof TextResponseBody) {
                throw new InvalidArgumentException('HTTP transport expects TextResponseBody for text payloads.');
            }

            $payload = $body->content;
        }

        return new PreparedTransportResponse(
            transportType: $this->type(),
            payload: $payload,
            metadata: new TransportEmissionMetadata(
                status: $response->status(),
                headers: $response->metadata()->headers,
            ),
            streaming: false,
        );
    }
}
