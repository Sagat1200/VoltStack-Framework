<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Runtime;

use Quantum\Exceptions\Model\ReportReceipt;
use Quantum\Exceptions\Model\ReporterReceipt;
use Throwable;

final class OccurrenceRecord
{
    /**
     * @var array<string, bool>
     */
    private array $attemptedReporters = [];

    /**
     * @var array<string, ReporterReceipt>
     */
    private array $receiptsByReporter = [];

    public function __construct(
        private readonly string $occurrenceId,
        private readonly Throwable $throwable,
        private readonly float $registeredAt,
        private readonly bool $retainedInLedger = true,
    ) {
    }

    public function occurrenceId(): string
    {
        return $this->occurrenceId;
    }

    public function throwable(): Throwable
    {
        return $this->throwable;
    }

    public function throwableClass(): string
    {
        return $this->throwable::class;
    }

    public function registeredAt(): float
    {
        return $this->registeredAt;
    }

    public function retainedInLedger(): bool
    {
        return $this->retainedInLedger;
    }

    public function wasReporterAttempted(string $reporterId): bool
    {
        return isset($this->attemptedReporters[$reporterId]);
    }

    public function markReporterAttempted(string $reporterId): void
    {
        if ($reporterId === '') {
            throw new \InvalidArgumentException('OccurrenceRecord reporterId must not be empty.');
        }

        $this->attemptedReporters[$reporterId] = true;
    }

    public function addReporterReceipt(ReporterReceipt $receipt): void
    {
        $this->markReporterAttempted($receipt->reporterId);
        $this->receiptsByReporter[$receipt->reporterId] = $receipt;
    }

    /**
     * @return list<ReporterReceipt>
     */
    public function receipts(): array
    {
        return array_values($this->receiptsByReporter);
    }

    public function toReportReceipt(): ReportReceipt
    {
        return new ReportReceipt(
            occurrenceId: $this->occurrenceId,
            receipts: $this->receipts(),
            deduplicated: $this->attemptedReporters !== [] && count($this->receiptsByReporter) < count($this->attemptedReporters),
        );
    }
}
