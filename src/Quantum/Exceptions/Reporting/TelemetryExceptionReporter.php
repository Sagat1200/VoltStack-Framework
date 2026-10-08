<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Reporting;

use Quantum\Exceptions\Contracts\ExceptionReporterInterface;
use Quantum\Exceptions\Model\ReportBudget;
use Quantum\Exceptions\Model\ReporterReceipt;
use Quantum\Exceptions\Model\ReportRecord;
use Quantum\Exceptions\Enums\ReporterReceiptState;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Quantum\Telemetry\TelemetrySignal;

final class TelemetryExceptionReporter implements ExceptionReporterInterface
{
    public function __construct(
        private readonly TelemetryManagerInterface $telemetry,
        private readonly string $source = 'quantum.exceptions',
    ) {
        if ($source === '') {
            throw new \InvalidArgumentException('TelemetryExceptionReporter source must not be empty.');
        }
    }

    public function report(ReportRecord $record, ReportBudget $budget): ReporterReceipt
    {
        $deliveryId = sha1(sprintf('%s|%s|%s', static::class, $record->occurrenceId, $record->fingerprint));

        $this->telemetry->emit(new TelemetrySignal(
            name: 'voltstack.exceptions.reporting',
            type: 'event',
            source: $this->source,
            occurredAt: gmdate('c'),
            payload: [
                'occurrence_id' => $record->occurrenceId,
                'delivery_id' => $deliveryId,
                'fingerprint' => $record->fingerprint,
                'semantic' => [
                    'code' => $record->semantic->code,
                    'category' => $record->semantic->category->value,
                    'severity' => $record->semantic->severity->value,
                ],
                'diagnostic' => $record->diagnostic,
            ],
            attributes: [
                'reporter_id' => static::class,
                'receipt_state' => ReporterReceiptState::Accepted->value,
                'code' => $record->semantic->code,
                'category' => $record->semantic->category->value,
                'severity' => $record->semantic->severity->value,
                'transport' => is_string($record->correlation['transport'] ?? null) ? $record->correlation['transport'] : null,
            ],
            requestId: is_string($record->correlation['correlation_id'] ?? null) ? $record->correlation['correlation_id'] : null,
            traceId: is_string($record->correlation['trace_id'] ?? null) ? $record->correlation['trace_id'] : null,
            tenantId: is_string($record->correlation['tenant_id'] ?? null) ? $record->correlation['tenant_id'] : null,
        ));

        return new ReporterReceipt(
            reporterId: static::class,
            state: ReporterReceiptState::Accepted,
            deliveryId: $deliveryId,
        );
    }
}
