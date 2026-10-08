<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Reporting;

use Quantum\Controllers\Observability\Contracts\ControllerEventDispatcherInterface;
use Quantum\Exceptions\Contracts\ExceptionReporterInterface;
use Quantum\Exceptions\Events\ExceptionReportedEvent;
use Quantum\Exceptions\Enums\ReporterReceiptState;
use Quantum\Exceptions\Model\ReportBudget;
use Quantum\Exceptions\Model\ReporterReceipt;
use Quantum\Exceptions\Model\ReportRecord;

final class EventDispatcherExceptionReporter implements ExceptionReporterInterface
{
    public function __construct(
        private readonly ControllerEventDispatcherInterface $dispatcher,
    ) {
    }

    public function report(ReportRecord $record, ReportBudget $budget): ReporterReceipt
    {
        $deliveryId = sha1(sprintf('%s|%s|%s', static::class, $record->occurrenceId, $record->fingerprint));

        $this->dispatcher->dispatch(ExceptionReportedEvent::fromRecord(
            record: $record,
            reporterId: static::class,
            deliveryId: $deliveryId,
        ));

        return new ReporterReceipt(
            reporterId: static::class,
            state: ReporterReceiptState::Accepted,
            deliveryId: $deliveryId,
        );
    }
}
