<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Context\RecoveryContext;
use Quantum\Exceptions\Context\TransportContext;
use Quantum\Exceptions\Core\HandlingResult;
use Quantum\Exceptions\Enums\Effect;
use Quantum\Exceptions\Enums\HandlingResultKind;
use Quantum\Exceptions\Enums\RecoveryAction;
use Quantum\Exceptions\Enums\ReporterReceiptState;
use Quantum\Exceptions\Enums\RetryAdvice;
use Quantum\Exceptions\Enums\SemanticCategory;
use Quantum\Exceptions\Enums\SemanticSeverity;
use Quantum\Exceptions\Enums\WorkerDisposition;
use Quantum\Exceptions\Model\ExceptionDescriptor;
use Quantum\Exceptions\Model\FailureSnapshot;
use Quantum\Exceptions\Model\PublicError;
use Quantum\Exceptions\Model\RecoveryDecision;
use Quantum\Exceptions\Model\RenderedOutput;
use Quantum\Exceptions\Model\ReportBudget;
use Quantum\Exceptions\Model\ReporterReceipt;
use Quantum\Exceptions\Model\ReportReceipt;
use Quantum\Exceptions\Model\ReportRecord;
use Quantum\Exceptions\Model\SemanticError;
use Quantum\Exceptions\Model\TransportPlan;

final class QuantumExceptionContractsAndModelsTest extends TestCase
{
    public function test_context_dtos_can_be_instantiated(): void
    {
        $exceptionContext = new ExceptionContext(
            scopeId: 'scope-main',
            occurrenceId: 'occ-1',
            correlationId: 'corr-1',
            locale: 'es',
            debug: true,
            attributes: ['surface' => 'http'],
        );

        $transportContext = new TransportContext(
            kind: 'http',
            committed: false,
            routeProfile: 'api',
            accept: ['application/problem+json'],
            locale: 'es',
            spaVersion: 1,
        );

        $recoveryContext = new RecoveryContext(
            owner: 'database',
            effect: Effect::Unknown,
            idempotencyVerified: false,
            attempt: 1,
            deadlineMs: 5000,
            capabilities: ['retry_read' => true],
        );

        self::assertSame('scope-main', $exceptionContext->scopeId);
        self::assertSame('http', $transportContext->kind);
        self::assertSame(Effect::Unknown, $recoveryContext->effect);
    }

    public function test_descriptor_and_reporting_models_capture_contract_shape(): void
    {
        $failure = new FailureSnapshot(
            className: \RuntimeException::class,
            internalMessage: 'Exploded',
            phpCode: 500,
            frames: [['file' => 'demo.php', 'line' => 10]],
            causes: [],
            origin: 'controller',
            safeMetadata: ['request_id' => 'req-1'],
            truncated: false,
        );

        $semantic = new SemanticError(
            code: 'internal.error',
            category: SemanticCategory::Internal,
            messageKey: 'exceptions.internal.error',
            safeParameters: ['surface' => 'api'],
            severity: SemanticSeverity::Error,
            effect: Effect::Unknown,
            retryAdvice: RetryAdvice::Never,
        );

        $descriptor = new ExceptionDescriptor(
            occurrenceId: 'occ-2',
            contextSummary: ['scope' => 'request'],
            failure: $failure,
            semantic: $semantic,
        );

        $record = new ReportRecord(
            occurrenceId: 'occ-2',
            parentOccurrenceId: null,
            fingerprint: 'fingerprint-v1',
            semantic: $semantic,
            diagnostic: ['origin' => 'controller'],
            correlation: ['request_id' => 'req-1'],
        );

        $budget = new ReportBudget(maxBytes: 2048, maxAttempts: 1);
        $receipt = new ReporterReceipt('exceptions.log', ReporterReceiptState::Accepted, 3);
        $reportReceipt = new ReportReceipt('occ-2', [$receipt], false);

        self::assertSame('internal.error', $descriptor->semantic->code);
        self::assertSame('fingerprint-v1', $record->fingerprint);
        self::assertSame(2048, $budget->maxBytes);
        self::assertCount(1, $reportReceipt->receipts);
    }

    public function test_transport_public_error_and_handling_result_support_new_pipeline(): void
    {
        $publicError = new PublicError(
            code: 'validation.failed',
            message: 'Revisa los campos indicados.',
            occurrenceId: 'occ-3',
            fields: [['path' => '/email', 'code' => 'format.invalid']],
        );

        $transportPlan = new TransportPlan(
            target: 'http.problem_json',
            status: 422,
            headers: ['Cache-Control' => 'no-store'],
        );

        $output = new RenderedOutput(
            target: $transportPlan->target,
            bodyBytes: '{"code":"validation.failed"}',
            mediaType: 'application/problem+json',
            status: 422,
            safeHeaders: ['Cache-Control' => 'no-store'],
        );

        $reportReceipt = new ReportReceipt('occ-3');
        $result = HandlingResult::rendered(
            occurrenceId: 'occ-3',
            output: $output,
            reportReceipt: $reportReceipt,
            workerDisposition: WorkerDisposition::Reuse,
        );

        $decision = new RecoveryDecision(
            action: RecoveryAction::Reconcile,
            reasonCode: 'operation.indeterminate',
            delayMs: 250,
        );

        $jobResult = HandlingResult::jobDecision(
            occurrenceId: 'occ-3',
            decision: $decision,
        );

        self::assertSame('validation.failed', $publicError->code);
        self::assertSame(HandlingResultKind::Rendered, $result->kind);
        self::assertSame('application/problem+json', $result->output?->mediaType);
        self::assertSame(HandlingResultKind::JobDecision, $jobResult->kind);
    }
}
