<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Controllers\Observability\Engine\InMemoryControllerEventDispatcher;
use Quantum\Exceptions\Contracts\ExceptionReporterInterface;
use Quantum\Exceptions\Enums\ReporterReceiptState;
use Quantum\Exceptions\Enums\SemanticCategory;
use Quantum\Exceptions\Model\ReportBudget;
use Quantum\Exceptions\Model\ReporterReceipt;
use Quantum\Exceptions\Model\ReportRecord;
use Quantum\Exceptions\Model\SemanticError;
use Quantum\Exceptions\Reporting\EventDispatcherExceptionReporter;
use Quantum\Exceptions\Reporting\ExceptionReporterPipeline;
use Quantum\Exceptions\Reporting\ExceptionReportingPolicy;
use Quantum\Exceptions\Reporting\TelemetryExceptionReporter;
use Quantum\Telemetry\Engine\InMemoryTelemetryExporter;
use Quantum\Telemetry\Engine\TelemetryManager;

final class QuantumExceptionReportingTest extends TestCase
{
    public function test_pipeline_marks_policy_ignored_without_invoking_reporter(): void
    {
        $reporter = new TestRecordingReporter();
        $pipeline = new ExceptionReporterPipeline(
            reporters: [$reporter],
            policy: new ExceptionReportingPolicy(
                ignoredCodes: ['validation.failed'],
            ),
        );

        $receipt = $pipeline->report($this->makeRecord(), new ReportBudget());

        self::assertSame(0, $reporter->calls);
        self::assertCount(1, $receipt->receipts);
        self::assertSame(ReporterReceiptState::Skipped, $receipt->receipts[0]->state);
        self::assertSame('policy_ignored', $receipt->receipts[0]->reasonCode);
        self::assertSame(['policy_ignored'], $receipt->suppressionReasons);
    }

    public function test_pipeline_marks_sampled_out_when_sampling_rejects_record(): void
    {
        $reporter = new TestRecordingReporter();
        $pipeline = new ExceptionReporterPipeline(
            reporters: [$reporter],
            policy: new ExceptionReportingPolicy(
                sampleRatesByCode: ['validation.failed' => 0.0],
            ),
        );

        $receipt = $pipeline->report($this->makeRecord(), new ReportBudget());

        self::assertSame(0, $reporter->calls);
        self::assertCount(1, $receipt->receipts);
        self::assertSame(ReporterReceiptState::Skipped, $receipt->receipts[0]->state);
        self::assertSame('sampled_out', $receipt->receipts[0]->reasonCode);
        self::assertSame(['sampled_out'], $receipt->suppressionReasons);
    }

    public function test_pipeline_catches_reporter_failures_and_continues_with_next_reporter(): void
    {
        $reporter = new TestRecordingReporter();
        $pipeline = new ExceptionReporterPipeline([
            new ThrowingReporter(),
            $reporter,
        ]);

        $receipt = $pipeline->report($this->makeRecord(), new ReportBudget());

        self::assertCount(2, $receipt->receipts);
        self::assertSame(ReporterReceiptState::Failed, $receipt->receipts[0]->state);
        self::assertSame('reporter_failed', $receipt->receipts[0]->reasonCode);
        self::assertSame('exceptions.reporter_failed', $receipt->receipts[0]->errorCode);
        self::assertSame(1, $reporter->calls);
        self::assertSame(ReporterReceiptState::Accepted, $receipt->receipts[1]->state);
    }

    public function test_observability_reporters_emit_telemetry_signal_and_controller_event(): void
    {
        $exporter = new InMemoryTelemetryExporter();
        $dispatcher = new InMemoryControllerEventDispatcher();
        $pipeline = new ExceptionReporterPipeline([
            new TelemetryExceptionReporter(new TelemetryManager($exporter)),
            new EventDispatcherExceptionReporter($dispatcher),
        ]);

        $receipt = $pipeline->report($this->makeRecord(), new ReportBudget());

        self::assertCount(2, $receipt->receipts);
        self::assertSame(ReporterReceiptState::Accepted, $receipt->receipts[0]->state);
        self::assertSame(ReporterReceiptState::Accepted, $receipt->receipts[1]->state);

        $signal = $exporter->last();
        self::assertNotNull($signal);
        self::assertSame('voltstack.exceptions.reporting', $signal->name);
        self::assertSame('validation.failed', $signal->payload['semantic']['code'] ?? null);

        $event = $dispatcher->last();
        self::assertNotNull($event);
        self::assertSame('quantum.exceptions.reported', $event->name());
        self::assertSame('occ-123', $event->executionId());
        self::assertSame('v1', $event->payload()['policy_revision'] ?? null);
    }

    private function makeRecord(): ReportRecord
    {
        return new ReportRecord(
            occurrenceId: 'occ-123',
            parentOccurrenceId: null,
            fingerprint: 'fp-123',
            semantic: new SemanticError(
                code: 'validation.failed',
                category: SemanticCategory::Validation,
                messageKey: 'exceptions.validation.failed',
            ),
            diagnostic: [
                'policy_revision' => 'v1',
                'class' => \RuntimeException::class,
            ],
            correlation: [
                'correlation_id' => 'corr-123',
                'transport' => 'http',
            ],
        );
    }
}

final class TestRecordingReporter implements ExceptionReporterInterface
{
    public int $calls = 0;

    public function report(ReportRecord $record, ReportBudget $budget): ReporterReceipt
    {
        $this->calls++;

        return new ReporterReceipt(
            reporterId: static::class,
            state: ReporterReceiptState::Accepted,
        );
    }
}

final class ThrowingReporter implements ExceptionReporterInterface
{
    public function report(ReportRecord $record, ReportBudget $budget): ReporterReceipt
    {
        throw new \RuntimeException('boom');
    }
}
