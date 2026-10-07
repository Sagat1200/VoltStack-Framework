<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Context\TransportContext;
use Quantum\Exceptions\Contracts\ExceptionRendererInterface;
use Quantum\Exceptions\Contracts\ExceptionReporterInterface;
use Quantum\Exceptions\Contracts\TransportMapperInterface;
use Quantum\Exceptions\Core\ExceptionManager;
use Quantum\Exceptions\Core\HandlingResult;
use Quantum\Exceptions\Enums\HandlingResultKind;
use Quantum\Exceptions\Enums\RecoveryAction;
use Quantum\Exceptions\Enums\ReporterReceiptState;
use Quantum\Exceptions\Mapping\DeterministicSemanticExceptionMapper;
use Quantum\Exceptions\Model\ExceptionDescriptor;
use Quantum\Exceptions\Model\PublicError;
use Quantum\Exceptions\Model\RenderedOutput;
use Quantum\Exceptions\Model\ReportBudget;
use Quantum\Exceptions\Model\ReporterReceipt;
use Quantum\Exceptions\Model\ReportRecord;
use Quantum\Exceptions\Model\TransportPlan;
use Quantum\Exceptions\Normalization\ThrowableNormalizer;
use Quantum\Exceptions\Recovery\DeterministicRecoveryPolicy;
use Quantum\Exceptions\Reporting\ExceptionReporterPipeline;
use Quantum\Exceptions\Runtime\ExceptionScopeLifecycleManager;
use Quantum\Validation\Exceptions\ValidationException;

final class QuantumExceptionManagerTest extends TestCase
{
    public function test_manager_renders_transport_output_for_validation_failure(): void
    {
        $manager = new ExceptionManager(
            normalizer: new ThrowableNormalizer(),
            semanticMapper: DeterministicSemanticExceptionMapper::standard(),
            reporterPipeline: new ExceptionReporterPipeline([new RecordingReporter()]),
            recoveryPolicy: new DeterministicRecoveryPolicy(),
            transportMapper: new class implements TransportMapperInterface {
                public function map(ExceptionDescriptor $descriptor, TransportContext $context): TransportPlan
                {
                    return new TransportPlan(
                        target: 'http.problem_json',
                        status: 422,
                        headers: ['Content-Language' => $context->locale ?? 'en'],
                    );
                }
            },
            renderer: new class implements ExceptionRendererInterface {
                public function render(PublicError $error, TransportPlan $plan): RenderedOutput
                {
                    return new RenderedOutput(
                        target: $plan->target,
                        bodyBytes: json_encode([
                            'code' => $error->code,
                            'message' => $error->message,
                            'fields' => $error->fields,
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                        mediaType: 'application/problem+json',
                        status: $plan->status,
                        safeHeaders: $plan->headers,
                    );
                }
            },
        );

        $result = $manager->handle(
            new ValidationException(['email' => ['invalid']]),
            new ExceptionContext(
                scopeId: 'scope-http',
                locale: 'es',
                attributes: [
                    'surface' => 'http',
                    'transport_kind' => 'http',
                    'transport_accept' => ['application/problem+json'],
                ],
            ),
        );

        self::assertSame(HandlingResultKind::Rendered, $result->kind);
        self::assertSame(422, $result->output?->status);
        self::assertStringContainsString('validation.failed', $result->output?->bodyBytes ?? '');
    }

    public function test_manager_returns_job_decision_for_job_surface(): void
    {
        $manager = new ExceptionManager(
            normalizer: new ThrowableNormalizer(),
            semanticMapper: DeterministicSemanticExceptionMapper::standard(),
            reporterPipeline: new ExceptionReporterPipeline(),
            recoveryPolicy: new DeterministicRecoveryPolicy(),
        );

        $result = $manager->handle(
            new \Quantum\Database\Transaction\TransactionException('fail'),
            new ExceptionContext(
                scopeId: 'scope-job',
                attributes: [
                    'surface' => 'job',
                    'execution_owner' => 'job',
                ],
            ),
        );

        self::assertSame(HandlingResultKind::JobDecision, $result->kind);
        self::assertSame(RecoveryAction::Reconcile, $result->decision?->action);
    }

    public function test_manager_deduplicates_reporters_per_occurrence_within_scope(): void
    {
        $reporter = new RecordingReporter();
        $scope = (new ExceptionScopeLifecycleManager())->start();
        $manager = new ExceptionManager(
            normalizer: new ThrowableNormalizer(),
            semanticMapper: DeterministicSemanticExceptionMapper::standard(),
            reporterPipeline: new ExceptionReporterPipeline([$reporter]),
            recoveryPolicy: new DeterministicRecoveryPolicy(),
        );

        $throwable = new ValidationException(['email' => ['invalid']]);
        $context = $scope->createContext();

        $first = $manager->report($throwable, $context);
        $second = $manager->report($throwable, $context);

        self::assertCount(1, $first->receipts);
        self::assertCount(1, $second->receipts);
        self::assertFalse($first->deduplicated);
        self::assertTrue($second->deduplicated);
        self::assertSame(1, $reporter->calls);
    }

    public function test_manager_aborts_transport_when_response_is_already_committed(): void
    {
        $manager = new ExceptionManager(
            normalizer: new ThrowableNormalizer(),
            semanticMapper: DeterministicSemanticExceptionMapper::standard(),
        );

        $result = $manager->handle(
            new \RuntimeException('after commit'),
            new ExceptionContext(
                scopeId: 'scope-committed',
                attributes: [
                    'surface' => 'http',
                    'transport_kind' => 'http',
                    'transport_committed' => true,
                ],
            ),
        );

        self::assertSame(HandlingResultKind::AbortTransport, $result->kind);
        self::assertSame('internal.error', $result->reasonCode);
    }
}

final class RecordingReporter implements ExceptionReporterInterface
{
    public int $calls = 0;

    public function report(ReportRecord $record, ReportBudget $budget): ReporterReceipt
    {
        $this->calls++;

        return new ReporterReceipt(
            reporterId: self::class,
            state: ReporterReceiptState::Accepted,
            durationMs: 1,
        );
    }
}
