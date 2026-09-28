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
use VoltStack\Framework\Application;

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
}
