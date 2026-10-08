<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Reporting;

use Quantum\Exceptions\Contracts\ExceptionReporterInterface;
use Quantum\Exceptions\Enums\ReporterReceiptState;
use Quantum\Exceptions\Model\ReportBudget;
use Quantum\Exceptions\Model\ReportReceipt;
use Quantum\Exceptions\Model\ReportRecord;
use Quantum\Exceptions\Model\ReporterReceipt;
use Quantum\Exceptions\Runtime\OccurrenceRegistry;
use Throwable;

final class ExceptionReporterPipeline
{
    /**
     * @var list<ExceptionReporterInterface>
     */
    private array $reporters;

    /**
     * @param iterable<ExceptionReporterInterface> $reporters
     */
    public function __construct(
        iterable $reporters = [],
        ?ExceptionReportingPolicy $policy = null,
    ) {
        $this->reporters = array_values(is_array($reporters) ? $reporters : iterator_to_array($reporters, false));
        $this->policy = $policy ?? ExceptionReportingPolicy::defaults();
    }

    private readonly ExceptionReportingPolicy $policy;

    public function report(
        ReportRecord $record,
        ReportBudget $budget,
        ?OccurrenceRegistry $registry = null,
    ): ReportReceipt {
        $receipts = [];
        $deduplicated = false;
        $extraReasons = [];
        $attemptNumber = 0;

        foreach ($this->reporters as $reporter) {
            $reporterId = $reporter::class;
            $attemptNumber++;

            if ($registry !== null && $registry->wasReporterAttempted($record->occurrenceId, $reporterId)) {
                $deduplicated = true;
                $extraReasons[] = 'duplicate';
                continue;
            }

            $decision = $this->policy->decide(
                record: $record,
                reporterId: $reporterId,
                budget: $budget,
                attemptNumber: $attemptNumber,
                budgetExpired: $this->budgetExpired($budget),
            );

            if (! $decision->shouldReport) {
                $receipt = new ReporterReceipt(
                    reporterId: $reporterId,
                    state: $decision->state,
                    reasonCode: $decision->reasonCode,
                );

                $receipts[] = $receipt;

                if ($registry !== null) {
                    $registry->markReporterAttempted($record->occurrenceId, $reporterId);
                    $registry->addReporterReceipt($record->occurrenceId, $receipt);
                }

                if ($decision->reasonCode !== null) {
                    $extraReasons[] = $decision->reasonCode;
                }

                continue;
            }

            if ($registry !== null) {
                $registry->markReporterAttempted($record->occurrenceId, $reporterId);
            }

            try {
                $startedAt = microtime(true);
                $receipt = $this->normalizeReceipt($reporterId, $reporter->report($record, $budget), $startedAt);
            } catch (Throwable) {
                $receipt = new ReporterReceipt(
                    reporterId: $reporterId,
                    state: ReporterReceiptState::Failed,
                    errorCode: 'exceptions.reporter_failed',
                    reasonCode: 'reporter_failed',
                );
            }

            $receipts[] = $receipt;

            if ($registry !== null) {
                $registry->addReporterReceipt($record->occurrenceId, $receipt);
            }
        }

        if ($registry !== null) {
            $registryReceipt = $registry->reportReceiptFor($record->occurrenceId);

            return new ReportReceipt(
                occurrenceId: $registryReceipt->occurrenceId,
                receipts: $registryReceipt->receipts,
                deduplicated: $deduplicated || $registryReceipt->deduplicated,
                suppressionReasons: $this->mergeReasons($registryReceipt->suppressionReasons, $extraReasons),
            );
        }

        return new ReportReceipt(
            occurrenceId: $record->occurrenceId,
            receipts: $receipts,
            deduplicated: false,
            suppressionReasons: $this->suppressionReasonsFrom($receipts, $extraReasons),
        );
    }

    private function budgetExpired(ReportBudget $budget): bool
    {
        return $budget->deadlineMonotonic !== null && hrtime(true) >= $budget->deadlineMonotonic;
    }

    private function normalizeReceipt(string $reporterId, ReporterReceipt $receipt, float $startedAt): ReporterReceipt
    {
        if ($receipt->reporterId === $reporterId && $receipt->durationMs > 0) {
            return $receipt;
        }

        return new ReporterReceipt(
            reporterId: $reporterId,
            state: $receipt->state,
            durationMs: $receipt->durationMs > 0 ? $receipt->durationMs : $this->durationFrom($startedAt),
            errorCode: $receipt->errorCode,
            reasonCode: $receipt->reasonCode,
            deliveryId: $receipt->deliveryId,
        );
    }

    private function durationFrom(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * @param list<ReporterReceipt> $receipts
     * @param list<string> $extraReasons
     * @return list<string>
     */
    private function suppressionReasonsFrom(array $receipts, array $extraReasons = []): array
    {
        $reasons = $extraReasons;

        foreach ($receipts as $receipt) {
            if ($receipt->reasonCode !== null && $receipt->reasonCode !== '') {
                $reasons[] = $receipt->reasonCode;
            }
        }

        return $this->mergeReasons([], $reasons);
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     * @return list<string>
     */
    private function mergeReasons(array $left, array $right): array
    {
        return array_values(array_unique(array_filter(array_merge($left, $right), static fn (mixed $reason): bool => is_string($reason) && $reason !== '')));
    }
}
