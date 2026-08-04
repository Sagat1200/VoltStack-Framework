<?php

declare(strict_types=1);

namespace Quantum\Transport\Emitters;

use Quantum\Transport\Contracts\PreparedTransportResponseInterface;
use Quantum\Transport\Contracts\TransportEmitterInterface;
use Quantum\Transport\Enums\TransportStatus;
use Quantum\Transport\Runtime\TransportContext;
use Quantum\Transport\Runtime\TransportResult;

final class NullTransportEmitter implements TransportEmitterInterface
{
    public function emit(PreparedTransportResponseInterface $response, TransportContext $context): TransportResult
    {
        return new TransportResult(
            status: TransportStatus::Completed,
            bytesEmitted: 0,
            completed: true,
            connectionClosed: false,
        );
    }
}

