<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Reporting;

use Quantum\Exceptions\Contracts\ExceptionReporterInterface;
use Quantum\Exceptions\Model\ReportBudget;
use Quantum\Exceptions\Model\ReportReceipt;
use Quantum\Exceptions\Model\ReportRecord;
use Quantum\Exceptions\Model\ReporterReceipt;
use Quantum\Exceptions\Runtime\OccurrenceRegistry;

final class ExceptionReporterPipeline
{
    /**
     * @var list<ExceptionReporterInterface>
     */
    private array $reporters;

    /**
     * @param iterable<ExceptionReporterInterface> $reporters
     */
    public function __construct(iterable $reporters = [])
    {
        $this->reporters = array_values(is_array($reporters) ? $reporters : iterator_to_array($reporters, false));
    }

    public function report(
        ReportRecord $record,
        ReportBudget $budget,
        ?OccurrenceRegistry $registry = null,
    ): ReportReceipt {
        $receipts = [];
        $deduplicated = false;

        foreach ($this->reporters as $reporter) {
            $reporterId = $reporter::class;

            if ($registry !== null && $registry->wasReporterAttempted($record->occurrenceId, $reporterId)) {
                $deduplicated = true;
                continue;
            }

            if ($registry !== null) {
                $registry->markReporterAttempted($record->occurrenceId, $reporterId);
            }

            $receipt = $reporter->report($record, $budget);

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
            );
        }

        return new ReportReceipt(
            occurrenceId: $record->occurrenceId,
            receipts: $receipts,
            deduplicated: false,
        );
    }
}
