<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Model;

final readonly class ReportReceipt
{
    /**
     * @param list<ReporterReceipt> $receipts
     * @param list<string> $suppressionReasons
     */
    public function __construct(
        public string $occurrenceId,
        public array $receipts = [],
        public bool $deduplicated = false,
        public array $suppressionReasons = [],
    ) {
        if ($occurrenceId === '') {
            throw new \InvalidArgumentException('ReportReceipt occurrenceId must not be empty.');
        }

        foreach ($suppressionReasons as $reason) {
            if (! is_string($reason) || $reason === '') {
                throw new \InvalidArgumentException('ReportReceipt suppressionReasons must contain only non-empty strings.');
            }
        }
    }
}
