<?php

declare(strict_types=1);

namespace Quantum\Transport\Emitters;

use Quantum\Transport\Contracts\PreparedTransportResponseInterface;
use Quantum\Transport\Contracts\TransportEmitterInterface;
use Quantum\Transport\Enums\TransportStatus;
use Quantum\Transport\Runtime\TransportContext;
use Quantum\Transport\Runtime\TransportResult;

final class HttpSapiEmitter implements TransportEmitterInterface
{
    public function emit(PreparedTransportResponseInterface $response, TransportContext $context): TransportResult
    {
        $metadata = $response->metadata();

        http_response_code($metadata->status);

        foreach ($metadata->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        $payload = $response->payload();
        $bytes = 0;

        if (is_string($payload) && $payload !== '') {
            $bytes = strlen($payload);
            echo $payload;
        }

        return new TransportResult(
            status: TransportStatus::Completed,
            bytesEmitted: $bytes,
            completed: true,
            connectionClosed: false,
        );
    }
}
