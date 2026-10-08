<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Reporting;

use Closure;
use Quantum\Exceptions\Contracts\ExceptionReporterInterface;
use Quantum\Exceptions\Enums\ReporterReceiptState;
use Quantum\Exceptions\Model\ReportBudget;
use Quantum\Exceptions\Model\ReporterReceipt;
use Quantum\Exceptions\Model\ReportRecord;

final class StructuredErrorLogReporter implements ExceptionReporterInterface
{
    private ?Closure $writer;

    public function __construct(?callable $writer = null)
    {
        $this->writer = $writer !== null ? Closure::fromCallable($writer) : null;
    }

    public function report(ReportRecord $record, ReportBudget $budget): ReporterReceipt
    {
        $deliveryId = sha1(sprintf('%s|%s|%s', static::class, $record->occurrenceId, $record->fingerprint));
        $payload = json_encode([
            'delivery_id' => $deliveryId,
            'occurrence_id' => $record->occurrenceId,
            'fingerprint' => $record->fingerprint,
            'semantic' => [
                'code' => $record->semantic->code,
                'category' => $record->semantic->category->value,
                'severity' => $record->semantic->severity->value,
            ],
            'diagnostic' => $record->diagnostic,
            'correlation' => $record->correlation,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            throw new \RuntimeException('StructuredErrorLogReporter failed to encode the report payload.');
        }

        if ($this->writer !== null) {
            ($this->writer)($payload, $record);
        } else {
            error_log($payload);
        }

        return new ReporterReceipt(
            reporterId: static::class,
            state: ReporterReceiptState::Accepted,
            deliveryId: $deliveryId,
        );
    }
}
