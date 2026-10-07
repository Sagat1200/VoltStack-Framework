<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Exceptions\Enums\ReporterReceiptState;
use Quantum\Exceptions\Model\ReporterReceipt;
use Quantum\Exceptions\Runtime\ExceptionRuntimeLimits;
use Quantum\Exceptions\Runtime\ExceptionScopeLifecycleManager;
use Quantum\Exceptions\Runtime\OccurrenceRegistry;
use Quantum\Http\Request;
use VoltStack\Runtime\Context\RuntimeContext;

final class QuantumExceptionScopeTest extends TestCase
{
    public function test_scope_lifecycle_tracks_runtime_context_and_finalization(): void
    {
        $limits = new ExceptionRuntimeLimits(maxOccurrences: 4, maxHandlingDepth: 2);
        $lifecycle = new ExceptionScopeLifecycleManager(limits: $limits);
        $runtimeContext = new RuntimeContext(
            requestId: 'req-001',
            request: Request::create('/demo', 'GET'),
            startedAt: 123.45,
        );

        $scope = $lifecycle->start($runtimeContext);
        $context = $scope->createContext(correlationId: 'corr-001', locale: 'es', debug: true);

        self::assertSame('req-001', $scope->runtimeRequestId());
        self::assertSame(123.45, $scope->startedAt());
        self::assertSame($scope, $context->scope);
        self::assertSame($scope->id(), $runtimeContext->get('exceptions.scope_id'));

        $lifecycle->end($scope, $runtimeContext);

        self::assertTrue($scope->isFinalized());
        self::assertTrue($scope->registry()->isClosed());
        self::assertTrue($runtimeContext->get('exceptions.scope_finalized'));
    }

    public function test_scope_enforces_handling_depth_limit(): void
    {
        $lifecycle = new ExceptionScopeLifecycleManager(
            limits: new ExceptionRuntimeLimits(maxOccurrences: 4, maxHandlingDepth: 2),
        );

        $scope = $lifecycle->start();

        self::assertSame(1, $scope->enterHandling());
        self::assertSame(2, $scope->enterHandling());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('exceeds the configured limit');

        $scope->enterHandling();
    }

    public function test_occurrence_registry_reuses_identity_and_tracks_reporters(): void
    {
        $registry = new OccurrenceRegistry(maxOccurrences: 2);
        $throwable = new \RuntimeException('boom');

        $first = $registry->identify($throwable, 'occ-001');
        $second = $registry->identify($throwable, 'occ-999');

        self::assertSame($first, $second);
        self::assertSame('occ-001', $first->occurrenceId());

        $registry->markReporterAttempted($throwable, 'exceptions.log');
        $registry->addReporterReceipt($throwable, new ReporterReceipt(
            reporterId: 'exceptions.log',
            state: ReporterReceiptState::Accepted,
            durationMs: 4,
        ));

        self::assertTrue($registry->wasReporterAttempted($throwable, 'exceptions.log'));
        self::assertCount(1, $registry->receiptsFor($throwable));
        self::assertFalse($registry->reportReceiptFor($throwable)->deduplicated);
    }

    public function test_occurrence_registry_saturates_retained_ledger(): void
    {
        $registry = new OccurrenceRegistry(maxOccurrences: 1);

        $first = $registry->identify(new \RuntimeException('first'), 'occ-a');
        $secondThrowable = new \RuntimeException('second');
        $second = $registry->identify($secondThrowable, 'occ-b');

        self::assertTrue($first->retainedInLedger());
        self::assertFalse($second->retainedInLedger());
        self::assertSame(1, $registry->retainedCount());
        self::assertSame(1, $registry->droppedCount());
        self::assertNull($registry->findByOccurrenceId('occ-b'));
        self::assertSame($second, $registry->identify($secondThrowable));
    }
}
