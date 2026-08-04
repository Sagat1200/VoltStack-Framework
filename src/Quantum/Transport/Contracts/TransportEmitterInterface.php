<?php

declare(strict_types=1);

namespace Quantum\Transport\Contracts;

use Quantum\Transport\Runtime\TransportContext;
use Quantum\Transport\Runtime\TransportResult;

interface TransportEmitterInterface
{
    public function emit(PreparedTransportResponseInterface $response, TransportContext $context): TransportResult;
}

