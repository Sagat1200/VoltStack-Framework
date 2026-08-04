<?php

declare(strict_types=1);

namespace Quantum\Transport\Runtime;

use Quantum\Transport\Contracts\PreparedTransportResponseInterface;
use Quantum\Transport\Contracts\ResponseInterface;
use Quantum\Transport\Enums\TransportStatus;
use Throwable;

final class TransportExecution
{
    public TransportStatus $status;
    public ?PreparedTransportResponseInterface $prepared = null;
    public ?TransportResult $result = null;
    public ?Throwable $exception = null;
    public bool $emissionStarted = false;

    public function __construct(
        public ResponseInterface $response,
        public TransportContext $context,
    ) {
        $this->status = TransportStatus::Pending;
    }
}
