<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Model;

use Quantum\Exceptions\Enums\ReporterReceiptState;

final readonly class ReporterReceipt
{
    public function __construct(
        public string $reporterId,
        public ReporterReceiptState $state,
        public int $durationMs = 0,
        public ?string $errorCode = null,
        public ?string $reasonCode = null,
        public ?string $deliveryId = null,
    ) {
        if ($reporterId === '') {
            throw new \InvalidArgumentException('ReporterReceipt reporterId must not be empty.');
        }

        if ($durationMs < 0) {
            throw new \InvalidArgumentException('ReporterReceipt durationMs must be greater than or equal to zero.');
        }

        if ($errorCode !== null && $errorCode === '') {
            throw new \InvalidArgumentException('ReporterReceipt errorCode must not be empty when provided.');
        }

        if ($reasonCode !== null && $reasonCode === '') {
            throw new \InvalidArgumentException('ReporterReceipt reasonCode must not be empty when provided.');
        }

        if ($deliveryId !== null && $deliveryId === '') {
            throw new \InvalidArgumentException('ReporterReceipt deliveryId must not be empty when provided.');
        }
    }
}
