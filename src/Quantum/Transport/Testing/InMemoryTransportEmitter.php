<?php

declare(strict_types=1);

namespace Quantum\Transport\Testing;

use Quantum\Transport\Contracts\PreparedTransportResponseInterface;
use Quantum\Transport\Contracts\TransportEmitterInterface;
use Quantum\Transport\Enums\TransportStatus;
use Quantum\Transport\Runtime\TransportContext;
use Quantum\Transport\Runtime\TransportResult;

final class InMemoryTransportEmitter implements TransportEmitterInterface
{
    private array $emitted = [];

    public function emit(PreparedTransportResponseInterface $response, TransportContext $context): TransportResult
    {
        $this->emitted[] = [
            'response' => $response,
            'context' => $context,
        ];

        $payload = $response->payload();
        $bytes = is_string($payload) ? strlen($payload) : 0;

        return new TransportResult(
            status: TransportStatus::Completed,
            bytesEmitted: $bytes,
            completed: true,
            connectionClosed: false,
        );
    }

    public function emitted(): array
    {
        return $this->emitted;
    }

    public function reset(): void
    {
        $this->emitted = [];
    }
}

