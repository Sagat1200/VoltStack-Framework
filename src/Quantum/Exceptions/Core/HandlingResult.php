<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Core;

use Quantum\Exceptions\Enums\HandlingResultKind;
use Quantum\Exceptions\Enums\WorkerDisposition;
use Quantum\Exceptions\Model\ExceptionDescriptor;
use Quantum\Exceptions\Model\RecoveryDecision;
use Quantum\Exceptions\Model\RenderedOutput;
use Quantum\Exceptions\Model\ReportReceipt;

final readonly class HandlingResult
{
    public function __construct(
        public HandlingResultKind $kind,
        public string $occurrenceId,
        public ?ReportReceipt $reportReceipt = null,
        public WorkerDisposition $workerDisposition = WorkerDisposition::Reuse,
        public ?RenderedOutput $output = null,
        public ?ExceptionDescriptor $descriptor = null,
        public ?RecoveryDecision $decision = null,
        public ?string $reasonCode = null,
    ) {
        if ($occurrenceId === '') {
            throw new \InvalidArgumentException('HandlingResult occurrenceId must not be empty.');
        }
    }

    public static function rendered(
        string $occurrenceId,
        RenderedOutput $output,
        ?ReportReceipt $reportReceipt = null,
        WorkerDisposition $workerDisposition = WorkerDisposition::Reuse,
    ): self {
        return new self(
            kind: HandlingResultKind::Rendered,
            occurrenceId: $occurrenceId,
            reportReceipt: $reportReceipt,
            workerDisposition: $workerDisposition,
            output: $output,
        );
    }

    public static function propagate(
        string $occurrenceId,
        ExceptionDescriptor $descriptor,
        ?ReportReceipt $reportReceipt = null,
        WorkerDisposition $workerDisposition = WorkerDisposition::Reuse,
    ): self {
        return new self(
            kind: HandlingResultKind::Propagate,
            occurrenceId: $occurrenceId,
            reportReceipt: $reportReceipt,
            workerDisposition: $workerDisposition,
            descriptor: $descriptor,
        );
    }

    public static function jobDecision(
        string $occurrenceId,
        RecoveryDecision $decision,
        ?ReportReceipt $reportReceipt = null,
        WorkerDisposition $workerDisposition = WorkerDisposition::Reuse,
    ): self {
        return new self(
            kind: HandlingResultKind::JobDecision,
            occurrenceId: $occurrenceId,
            reportReceipt: $reportReceipt,
            workerDisposition: $workerDisposition,
            decision: $decision,
        );
    }

    public static function abortTransport(
        string $occurrenceId,
        string $reasonCode,
        ?ReportReceipt $reportReceipt = null,
        WorkerDisposition $workerDisposition = WorkerDisposition::Terminate,
    ): self {
        return new self(
            kind: HandlingResultKind::AbortTransport,
            occurrenceId: $occurrenceId,
            reportReceipt: $reportReceipt,
            workerDisposition: $workerDisposition,
            reasonCode: $reasonCode,
        );
    }

    public static function emergency(
        string $occurrenceId,
        ?RenderedOutput $output = null,
        ?ReportReceipt $reportReceipt = null,
        WorkerDisposition $workerDisposition = WorkerDisposition::Terminate,
    ): self {
        return new self(
            kind: HandlingResultKind::Emergency,
            occurrenceId: $occurrenceId,
            reportReceipt: $reportReceipt,
            workerDisposition: $workerDisposition,
            output: $output,
        );
    }
}
