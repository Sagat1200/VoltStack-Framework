<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Contracts\AuthorizationContextFactoryInterface;
use Quantum\Config\ConfigRepository;
use Quantum\Http\Request;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\ScopeManager;

final class AuthorizationContextFactoryRuntimeContextTest extends TestCase
{
    public function test_factory_builds_context_from_command_runtime_metadata(): void
    {
        $app = new Application(sys_get_temp_dir());
        $config = $app->make(ConfigRepository::class);
        $config->set('authorization.authority.scope_resolution.enabled', true);
        $scopeManager = $app->make(ScopeManager::class);

        $scopeManager->beginCommand('reports:sync', null, [
            'tenant_id' => 'acme-cli',
            'runtime.channel' => 'cli',
        ]);

        try {
            $factory = $app->make(AuthorizationContextFactoryInterface::class);
            $context = $factory->create();

            self::assertSame('cli', $context->channel());
            self::assertSame('acme-cli', $context->tenantId());
            self::assertSame('acme-cli', $context->attribute('tenant.id'));
            self::assertSame('tenant:acme-cli', (string) $context->attribute('authorization.scope'));
            self::assertNotNull($context->attribute('runtime_context'));
            self::assertNotNull($context->attribute('request'));
        } finally {
            $scopeManager->end();
        }
    }

    public function test_factory_derives_tenant_from_job_request_route_parameters(): void
    {
        $app = new Application(sys_get_temp_dir());
        $config = $app->make(ConfigRepository::class);
        $config->set('authorization.authority.scope_resolution.enabled', true);
        $scopeManager = $app->make(ScopeManager::class);

        $request = Request::create('/_runtime/job/reindex-search', 'POST');
        $request->setRouteParameters(['tenant' => 'acme-worker']);
        $scopeManager->beginJob('reindex:search', $request, [
            'runtime.channel' => 'worker',
        ]);

        try {
            $factory = $app->make(AuthorizationContextFactoryInterface::class);
            $context = $factory->create();

            self::assertSame('worker', $context->channel());
            self::assertSame('acme-worker', $context->tenantId());
            self::assertSame('tenant:acme-worker', (string) $context->attribute('authorization.scope'));
            self::assertSame('acme-worker', $context->attribute('tenant_id'));
        } finally {
            $scopeManager->end();
        }
    }
}
