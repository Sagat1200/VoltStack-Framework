<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Authority\InMemoryAuthorityRepository;
use Quantum\Authorization\Contracts\AuthorizationManagerInterface;
use Quantum\Authorization\Contracts\PrincipalInterface;
use Quantum\Authorization\Exceptions\AuthorizationDeniedException;
use Quantum\Authorization\Gate\GateRegistry;
use Quantum\Authorization\Principal\Principal;
use Quantum\Config\ConfigRepository;
use Quantum\Http\Request;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\ScopeManager;

final class AuthorizationMultiSurfaceIntegrationTest extends TestCase
{
    public function test_cli_surface_without_routematch_applies_gate_decisions_normally(): void
    {
        $basePath = sys_get_temp_dir();
        $app = new Application($basePath);
        /** @var GateRegistry $gates */
        $gates = $app->make(GateRegistry::class);
        $gates->define(
            'queue:process',
            static function (PrincipalInterface $principal, mixed $queueName): bool {
                return $principal->id() === 'worker-1' && $queueName === 'invoices';
            },
        );

        /** @var AuthorizationManagerInterface $manager */
        $manager = $app->make(AuthorizationManagerInterface::class);

        // Subject es valor plano (el stage de gates invoca $request->subject()->value()).
        $granted = $manager->check(
            ability: 'queue:process',
            subject: 'invoices',
            principal: new Principal('worker-1'),
        );

        self::assertTrue($granted);

        $denied = $manager->check(
            ability: 'queue:process',
            subject: 'invoices',
            principal: new Principal('worker-not-allowed'),
        );

        self::assertFalse($denied);
    }

    public function test_cli_job_surface_authorize_throws_denied_exception(): void
    {
        $basePath = sys_get_temp_dir();
        $app = new Application($basePath);
        $manager = $app->make(AuthorizationManagerInterface::class);

        $this->expectException(AuthorizationDeniedException::class);

        $manager->authorize(
            ability: 'jobs:process-something-never-granted',
            principal: new Principal('anon'),
        );
    }

    public function test_job_surface_authority_repository_respects_seed_grants(): void
    {
        $seed = [
            [
                'principal_id' => 'job-runner',
                'scope' => 'org:acme',
                'permissions' => ['view:reports'],
            ],
        ];

        $repository = new InMemoryAuthorityRepository($seed);

        self::assertTrue(
            $repository->hasPermission(principalId: 'job-runner', permission: 'view:reports', scope: 'org:acme'),
        );
        self::assertFalse(
            $repository->hasPermission(principalId: 'job-runner', permission: 'delete:reports', scope: 'org:acme'),
        );
    }

    public function test_global_helper_returns_manager_instance(): void
    {
        $basePath = sys_get_temp_dir();
        $app = new Application($basePath);

        $result = authorization()->decide(ability: new Ability('some:ability'));

        self::assertNotNull($result);
    }

    public function test_authority_disabled_allows_gates_downstream(): void
    {
        $basePath = sys_get_temp_dir();
        $app = new Application($basePath);
        $app->make(\Quantum\Config\ConfigRepository::class)->set('authorization.authority.enabled', false);

        $gates = $app->make(GateRegistry::class);
        $gates->define('reports:export', static fn (PrincipalInterface $principal): bool => true);

        $manager = $app->make(AuthorizationManagerInterface::class);

        self::assertTrue($manager->check(
            ability: 'reports:export',
            principal: new Principal('u1'),
        ));
    }

    public function test_command_surface_can_derive_tenant_scope_from_runtime_metadata(): void
    {
        $basePath = sys_get_temp_dir();
        $app = new Application($basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('authorization.authority.scope_resolution.enabled', true);

        /** @var GateRegistry $gates */
        $gates = $app->make(GateRegistry::class);
        $gates->define(
            'reports:command-tenant',
            static function (PrincipalInterface $principal, mixed $subject, \Quantum\Authorization\Context\AuthorizationContext $context): bool {
                return $principal->id() === 'cli-operator'
                    && $subject === 'nightly'
                    && $context->attribute('tenant.id') === 'acme-cli'
                    && (string) $context->attribute('authorization.scope') === 'tenant:acme-cli'
                    && $context->channel() === 'cli';
            },
        );

        $scopeManager = $app->make(ScopeManager::class);
        $scopeManager->beginCommand('reports:nightly', null, [
            'tenant_id' => 'acme-cli',
            'runtime.channel' => 'cli',
        ]);

        try {
            /** @var AuthorizationManagerInterface $manager */
            $manager = $app->make(AuthorizationManagerInterface::class);
            self::assertTrue($manager->check(
                ability: 'reports:command-tenant',
                subject: 'nightly',
                principal: new Principal('cli-operator'),
            ));
        } finally {
            $scopeManager->end();
        }
    }

    public function test_job_surface_can_derive_tenant_scope_from_runtime_request_parameters(): void
    {
        $basePath = sys_get_temp_dir();
        $app = new Application($basePath);
        $config = $app->make(ConfigRepository::class);
        $config->set('authorization.authority.scope_resolution.enabled', true);

        /** @var GateRegistry $gates */
        $gates = $app->make(GateRegistry::class);
        $gates->define(
            'jobs:tenant-process',
            static function (PrincipalInterface $principal, mixed $subject, \Quantum\Authorization\Context\AuthorizationContext $context): bool {
                return $principal->id() === 'worker-tenant'
                    && $subject === 'reindex-search'
                    && $context->attribute('tenant.id') === 'acme-worker'
                    && (string) $context->attribute('authorization.scope') === 'tenant:acme-worker'
                    && $context->channel() === 'worker';
            },
        );

        $request = Request::create('/_runtime/job/reindex-search', 'POST');
        $request->setRouteParameters(['tenant' => 'acme-worker']);

        $scopeManager = $app->make(ScopeManager::class);
        $scopeManager->beginJob('reindex:search', $request, [
            'runtime.channel' => 'worker',
        ]);

        try {
            /** @var AuthorizationManagerInterface $manager */
            $manager = $app->make(AuthorizationManagerInterface::class);
            self::assertTrue($manager->check(
                ability: 'jobs:tenant-process',
                subject: 'reindex-search',
                principal: new Principal('worker-tenant'),
            ));
        } finally {
            $scopeManager->end();
        }
    }
}
