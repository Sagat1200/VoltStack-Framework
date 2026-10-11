<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Container\CleanupReport;
use Quantum\Container\Container;
use Quantum\Container\ScopeKind;
use Quantum\Container\ScopeToken;
use Quantum\Container\WorkerHandle;

final class ScopeHandleTest extends TestCase
{
    public function test_open_scope_returns_an_active_handle_with_the_requested_kind(): void
    {
        $container = new Container();

        $handle = $container->openScope(ScopeKind::Request);

        self::assertTrue($handle->isActive());
        self::assertSame(ScopeKind::Request, $handle->kind());
        self::assertSame(ScopeKind::Request, $handle->token()->kind);
        self::assertTrue($container->hasActiveScope());
    }

    public function test_scope_handle_run_executes_callback_with_token_and_closes_scope(): void
    {
        $container = new Container();
        $handle = $container->openScope(ScopeKind::Request);

        $receivedToken = null;
        $result = $handle->run(static function (ScopeToken $token) use (&$receivedToken): string {
            $receivedToken = $token;

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertInstanceOf(ScopeToken::class, $receivedToken);
        self::assertSame(ScopeKind::Request, $receivedToken->kind);
        self::assertFalse($handle->isActive());
        self::assertFalse($container->hasActiveScope());
    }

    public function test_scope_handle_run_is_single_use(): void
    {
        $container = new Container();
        $handle = $container->openScope(ScopeKind::Request);

        $handle->run(static fn(): string => 'first');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('can only be called once');

        $handle->run(static fn(): string => 'second');
    }

    public function test_scope_handle_close_returns_cleanup_report_and_is_idempotent(): void
    {
        $container = new Container();
        $handle = $container->openScope(ScopeKind::Job);

        $first = $handle->close();
        $second = $handle->close();

        self::assertInstanceOf(CleanupReport::class, $first);
        self::assertSame(ScopeKind::Job, $first->kind);
        self::assertTrue($first->ok());
        self::assertFalse($container->hasActiveScope());
        self::assertSame($first->frameId, $second->frameId);
    }

    public function test_scope_handle_supports_seeds_before_resolution(): void
    {
        $container = new Container();
        $handle = $container->openScope(ScopeKind::Request);

        $handle->seed('request.id', 'req-42');

        self::assertTrue($handle->hasSeed('request.id'));
        self::assertSame('req-42', $handle->getSeed('request.id'));
        self::assertFalse($handle->hasSeed('missing'));

        $handle->close();
    }

    public function test_scope_handle_run_closes_scope_even_when_callback_throws(): void
    {
        $container = new Container();
        $handle = $container->openScope(ScopeKind::Request);

        try {
            $handle->run(static function (): never {
                throw new \RuntimeException('boom');
            });
            self::fail('Expected exception was not thrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertFalse($handle->isActive());
        self::assertFalse($container->hasActiveScope());
        $report = $handle->close();
        self::assertFalse($report->ok());
    }

    public function test_open_worker_scope_returns_worker_handle_and_can_enter_unit_scopes(): void
    {
        $container = new Container();

        $worker = $container->openWorkerScope();

        self::assertInstanceOf(WorkerHandle::class, $worker);
        self::assertSame(ScopeKind::Worker, $worker->token()->kind);

        $request = $worker->enter(ScopeKind::Request);
        self::assertSame(ScopeKind::Request, $request->kind());

        $requestReport = $request->close();
        self::assertTrue($requestReport->ok());

        $workerReport = $worker->close();
        self::assertTrue($workerReport->ok());
        self::assertFalse($worker->isPoisoned());
        self::assertFalse($container->hasActiveScope());
    }

    public function test_worker_handle_rejects_tenant_scope(): void
    {
        $container = new Container();
        $worker = $container->openWorkerScope();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only accepts Request, Job or Command');

        $worker->enter(ScopeKind::Tenant);
    }

    public function test_worker_handle_enter_accepts_seeds(): void
    {
        $container = new Container();
        $worker = $container->openWorkerScope();

        $request = $worker->enter(ScopeKind::Request, ['trace.id' => 'trace-1']);

        self::assertTrue($request->hasSeed('trace.id'));
        self::assertSame('trace-1', $request->getSeed('trace.id'));

        $request->close();
        $worker->close();
    }

    public function test_worker_handle_becomes_poisoned_when_unit_scope_fails_cleanup(): void
    {
        $container = new Container();
        $worker = $container->openWorkerScope();
        $request = $worker->enter(ScopeKind::Request);

        try {
            $request->run(static function (): never {
                throw new \RuntimeException('unit failed');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $workerReport = $worker->close();

        self::assertFalse($workerReport->ok());
        self::assertTrue($worker->isPoisoned());
    }

    public function test_open_scope_validates_scope_transition(): void
    {
        $container = new Container();
        $container->openScope(ScopeKind::Request);

        $this->expectException(\Quantum\Container\Exceptions\BindingResolutionException::class);

        // Request y Job son unidades hermanas incompatibles.
        $container->openScope(ScopeKind::Job);
    }
}
