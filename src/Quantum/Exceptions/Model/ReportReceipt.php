<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Model;

final readonly class ReportReceipt
{
    /**
     * @param list<ReporterReceipt> $receipts
     */
    public function __construct(
        public string $occurrenceId,
        public array $receipts = [],
        public bool $deduplicated = false,
    ) {
        if ($occurrenceId === '') {
            throw new \InvalidArgumentException('ReportReceipt occurrenceId must not be empty.');
        }
    }
}
