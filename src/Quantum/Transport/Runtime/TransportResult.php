<?php

declare(strict_types=1);

namespace Quantum\Transport\Runtime;

use Quantum\Transport\Enums\TransportStatus;
use Throwable;

final readonly class TransportResult
{
    public function __construct(
        public TransportStatus $status,
        public int $bytesEmitted,
        public bool $completed,
        public bool $connectionClosed,
        public bool $emissionStarted = false,
        public ?TransportExecution $execution = null,
        public ?Throwable $exception = null,
    ) {}
}
