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
    public function test_scope_manager_opens_and_closes_an_explicit_request_scope(): void
    {
        $app = new Application(sys_get_temp_dir());
        $scopeManager = $app->make(ScopeManager::class);

        self::assertFalse($app->hasActiveScope());
        $rootScopeId = $app->currentScopeId();

        $context = $scopeManager->begin(Request::create('/scopes'));

        self::assertTrue($app->hasActiveScope());
        self::assertNotSame($rootScopeId, $app->currentScopeId());
        self::assertSame($context->requestId(), RuntimeContext::current()?->requestId());

        $scopeManager->end();

        self::assertFalse($app->hasActiveScope());
        self::assertNull(RuntimeContext::current());
        self::assertSame($rootScopeId, $app->currentScopeId());
    }
}
