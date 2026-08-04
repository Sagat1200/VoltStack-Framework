<?php

declare(strict_types=1);

namespace Quantum\Transport\Contracts;

use Quantum\Transport\Runtime\TransportContext;

interface TransportAdapterInterface
{
    public function type(): string;

    public function supports(ResponseInterface $response, TransportContext $context): bool;

    public function prepare(ResponseInterface $response, TransportContext $context): PreparedTransportResponseInterface;
}

