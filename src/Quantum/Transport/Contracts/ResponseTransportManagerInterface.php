<?php

declare(strict_types=1);

namespace Quantum\Transport\Contracts;

use Quantum\Transport\Runtime\TransportContext;
use Quantum\Transport\Runtime\TransportResult;

interface ResponseTransportManagerInterface
{
    public function send(ResponseInterface $response, TransportContext $context): TransportResult;
}

