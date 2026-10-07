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
    ) {
        if ($reporterId === '') {
            throw new \InvalidArgumentException('ReporterReceipt reporterId must not be empty.');
        }

        if ($durationMs < 0) {
            throw new \InvalidArgumentException('ReporterReceipt durationMs must be greater than or equal to zero.');
        }
    }
}
