<?php

declare(strict_types=1);

namespace Quantum\Transport\Contracts;

use Quantum\Transport\Runtime\TransportEmissionMetadata;

interface PreparedTransportResponseInterface
{
    public function transportType(): string;

    public function payload(): mixed;

    public function metadata(): TransportEmissionMetadata;

    public function isStreaming(): bool;
}

