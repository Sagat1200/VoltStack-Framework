<?php

declare(strict_types=1);

namespace Quantum\Transport\Runtime;

use Quantum\Transport\Contracts\PreparedTransportResponseInterface;

final readonly class PreparedTransportResponse implements PreparedTransportResponseInterface
{
    public function __construct(
        private string $transportType,
        private mixed $payload,
        private TransportEmissionMetadata $metadata,
        private bool $streaming = false,
    ) {
    }

    public function transportType(): string
    {
        return $this->transportType;
    }

    public function payload(): mixed
    {
        return $this->payload;
    }

    public function metadata(): TransportEmissionMetadata
    {
        return $this->metadata;
    }

    public function isStreaming(): bool
    {
        return $this->streaming;
    }
}

