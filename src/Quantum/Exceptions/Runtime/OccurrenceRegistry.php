<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Runtime;

use Quantum\Exceptions\Model\ReportReceipt;
use Quantum\Exceptions\Model\ReporterReceipt;
use Throwable;
use WeakMap;

final class OccurrenceRegistry
{
    /**
     * @var WeakMap<Throwable, OccurrenceRecord>
     */
    private WeakMap $recordsByThrowable;

    /**
     * @var array<string, OccurrenceRecord>
     */
    private array $recordsById = [];

    private bool $closed = false;
    private int $droppedCount = 0;

    public function __construct(
        private readonly int $maxOccurrences = 128,
    ) {
        if ($maxOccurrences <= 0) {
            throw new \InvalidArgumentException('OccurrenceRegistry maxOccurrences must be greater than zero.');
        }

        $this->recordsByThrowable = new WeakMap();
    }

    public function identify(Throwable $throwable, ?string $occurrenceId = null): OccurrenceRecord
    {
        $this->assertOpen();

        if (isset($this->recordsByThrowable[$throwable])) {
            return $this->recordsByThrowable[$throwable];
        }

        $resolvedOccurrenceId = $this->normalizeOccurrenceId($occurrenceId);
        $retainedInLedger = count($this->recordsById) < $this->maxOccurrences;

        if (! $retainedInLedger) {
            $this->droppedCount++;
        }

        $record = new OccurrenceRecord(
            occurrenceId: $resolvedOccurrenceId,
            throwable: $throwable,
            registeredAt: microtime(true),
            retainedInLedger: $retainedInLedger,
        );

        $this->recordsByThrowable[$throwable] = $record;

        if ($retainedInLedger) {
            $this->recordsById[$resolvedOccurrenceId] = $record;
        }

        return $record;
    }

    public function has(Throwable $throwable): bool
    {
        $this->assertOpen();

        return isset($this->recordsByThrowable[$throwable]);
    }

    public function findByOccurrenceId(string $occurrenceId): ?OccurrenceRecord
    {
        $this->assertOpen();

        return $this->recordsById[$occurrenceId] ?? null;
    }

    public function markReporterAttempted(Throwable|string $target, string $reporterId): void
    {
        $this->resolveRecord($target)->markReporterAttempted($reporterId);
    }

    public function wasReporterAttempted(Throwable|string $target, string $reporterId): bool
    {
        return $this->resolveRecord($target)->wasReporterAttempted($reporterId);
    }

    public function addReporterReceipt(Throwable|string $target, ReporterReceipt $receipt): void
    {
        $this->resolveRecord($target)->addReporterReceipt($receipt);
    }

    /**
     * @return list<ReporterReceipt>
     */
    public function receiptsFor(Throwable|string $target): array
    {
        return $this->resolveRecord($target)->receipts();
    }

    public function reportReceiptFor(Throwable|string $target): ReportReceipt
    {
        return $this->resolveRecord($target)->toReportReceipt();
    }

    public function retainedCount(): int
    {
        $this->assertOpen();

        return count($this->recordsById);
    }

    public function droppedCount(): int
    {
        return $this->droppedCount;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->recordsById = [];
        $this->recordsByThrowable = new WeakMap();
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function resolveRecord(Throwable|string $target): OccurrenceRecord
    {
        $this->assertOpen();

        if ($target instanceof Throwable) {
            return $this->identify($target);
        }

        $record = $this->recordsById[$target] ?? null;

        if ($record === null) {
            throw new \LogicException(sprintf('Occurrence [%s] is not retained in the current registry.', $target));
        }

        return $record;
    }

    private function normalizeOccurrenceId(?string $occurrenceId): string
    {
        $value = trim((string) $occurrenceId);

        return $value !== '' ? $value : bin2hex(random_bytes(16));
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new \LogicException('OccurrenceRegistry is closed.');
        }
    }
}
