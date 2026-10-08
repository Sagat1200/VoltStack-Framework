<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Reporting;

use Quantum\Exceptions\Enums\ReporterReceiptState;

final readonly class ReportingPolicyDecision
{
    public function __construct(
        public bool $shouldReport,
        public ReporterReceiptState $state = ReporterReceiptState::Skipped,
        public ?string $reasonCode = null,
    ) {
        if ($reasonCode !== null && $reasonCode === '') {
            throw new \InvalidArgumentException('ReportingPolicyDecision reasonCode must not be empty when provided.');
        }
    }

    public static function allow(): self
    {
        return new self(true, ReporterReceiptState::Accepted);
    }

    public static function skip(string $reasonCode): self
    {
        return new self(false, ReporterReceiptState::Skipped, $reasonCode);
    }
}
