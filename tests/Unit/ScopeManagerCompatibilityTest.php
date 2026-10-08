<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Http\Request;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\RuntimeContext;
use VoltStack\Runtime\Context\ScopeManager;

final class ScopeManagerCompatibilityTest extends TestCase
{
    protected function tearDown(): void
    {
        RuntimeContext::setCurrent(null);

        parent::tearDown();
    }

    public function test_scope_manager_opens_and_closes_an_explicit_request_scope(): void
    {
        $app = new Application(sys_get_temp_dir());
        $scopeManager = $app->make(ScopeManager::class);

        self::assertFalse($app->hasActiveScope());
        $rootScopeId = $app->currentScopeId();

        $context = $scopeManager->beginRequest(Request::create('/scopes'));

        self::assertTrue($app->hasActiveScope());
        self::assertNotSame($rootScopeId, $app->currentScopeId());
        self::assertSame('request', $app->currentScopeKind());
        self::assertSame(1, $app->currentScopeDepth());
        self::assertSame($rootScopeId, $app->currentScopeParentId());
        self::assertSame($context->requestId(), RuntimeContext::current()?->requestId());

        $scopeManager->end();

        self::assertFalse($app->hasActiveScope());
        self::assertNull(RuntimeContext::current());
        self::assertSame('root', $app->currentScopeKind());
        self::assertSame(0, $app->currentScopeDepth());
        self::assertSame($rootScopeId, $app->currentScopeId());
    }

    public function test_scope_manager_opens_a_command_scope_with_synthetic_request_metadata(): void
    {
        $app = new Application(sys_get_temp_dir());
        $scopeManager = $app->make(ScopeManager::class);

        $context = $scopeManager->beginCommand('database:migrate');

        self::assertSame('command', $app->currentScopeKind());
        self::assertSame('/_cli/command/database-migrate', $context->request()->uri());
        self::assertSame('command', $context->get('runtime.scope_kind'));
        self::assertSame('cli', $context->get('runtime.channel'));
        self::assertSame('database:migrate', $context->get('runtime.unit_name'));
        self::assertSame($context->requestId(), RuntimeContext::current()?->requestId());

        $scopeManager->end();

        self::assertSame('root', $app->currentScopeKind());
        self::assertNull(RuntimeContext::current());
    }

    public function test_scope_manager_can_run_command_scope_and_restore_root_even_on_exception(): void
    {
        $app = new Application(sys_get_temp_dir());
        $scopeManager = $app->make(ScopeManager::class);

        try {
            $scopeManager->runInCommand(function () use ($app): never {
                self::assertSame('command', $app->currentScopeKind());
                throw new \RuntimeException('command failed');
            }, 'database:rollback');
            self::fail('The command callback exception should bubble up.');
        } catch (\RuntimeException $exception) {
            self::assertSame('command failed', $exception->getMessage());
        }

        self::assertSame('root', $app->currentScopeKind());
        self::assertNull(RuntimeContext::current());
        self::assertFalse($app->hasActiveScope());
    }

    public function test_scope_manager_can_run_job_scope_with_worker_metadata(): void
    {
        $app = new Application(sys_get_temp_dir());
        $scopeManager = $app->make(ScopeManager::class);

        $requestId = $scopeManager->runInJob(function () use ($app): string {
            $context = RuntimeContext::current();

            self::assertNotNull($context);
            self::assertSame('job', $app->currentScopeKind());
            self::assertSame('/_runtime/job/reindex-search', $context->request()->uri());
            self::assertSame('job', $context->get('runtime.scope_kind'));
            self::assertSame('worker', $context->get('runtime.channel'));
            self::assertSame('reindex:search', $context->get('runtime.unit_name'));

            return $context->requestId();
        }, 'reindex:search');

        self::assertIsString($requestId);
        self::assertSame('root', $app->currentScopeKind());
        self::assertNull(RuntimeContext::current());
    }

    public function test_runtime_context_activation_stack_restores_previous_context_when_top_slot_is_removed(): void
    {
        $outerContext = new RuntimeContext(
            'outer-request',
            Request::create('/outer'),
            microtime(true),
        );
        $innerContext = new RuntimeContext(
            'inner-request',
            Request::create('/inner'),
            microtime(true),
        );

        $outerSlot = RuntimeContext::activate($outerContext);
        $innerSlot = RuntimeContext::activate($innerContext);

        self::assertSame('inner-request', RuntimeContext::current()?->requestId());

        RuntimeContext::deactivate($innerSlot);
        self::assertSame('outer-request', RuntimeContext::current()?->requestId());

        RuntimeContext::deactivate($outerSlot);
        self::assertNull(RuntimeContext::current());
    }

    public function test_scope_manager_restores_previously_active_runtime_context_after_finishing_its_scope(): void
    {
        $outerContext = new RuntimeContext(
            'outer-request',
            Request::create('/outer'),
            microtime(true),
        );
        $outerSlot = RuntimeContext::activate($outerContext);

        try {
            $app = new Application(sys_get_temp_dir());
            $scopeManager = $app->make(ScopeManager::class);

            $scopedContext = $scopeManager->beginRequest(Request::create('/scoped'));

            self::assertSame($scopedContext->requestId(), RuntimeContext::current()?->requestId());

            $scopeManager->end();

            self::assertSame('outer-request', RuntimeContext::current()?->requestId());
        } finally {
            RuntimeContext::deactivate($outerSlot);
        }
    }
}
