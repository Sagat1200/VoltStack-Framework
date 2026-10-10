<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Contracts\AuthorizationManagerInterface;
use Quantum\Authorization\Core\AuthorizationManager;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Principal\PrincipalType;
use VoltStack\Framework\Application;

final class AuthorizationManagerImpersonationAndServiceTest extends TestCase
{
    public function test_manager_impersonate_produces_impersonated_principal_shape(): void
    {
        $app = new Application(sys_get_temp_dir());
        /** @var AuthorizationManagerInterface $manager */
        $manager = $app->make(AuthorizationManagerInterface::class);

        $bound = $manager->impersonate('originator_admin', 'target_tenant_user', 'tenant:acme');

        self::assertInstanceOf(\Quantum\Authorization\Core\BoundAuthorization::class, $bound);

        $reflection = new \ReflectionObject($bound);
        $principalProp = $reflection->getProperty('principal');
        $principalProp->setAccessible(true);
        $principal = $principalProp->getValue($bound);

        self::assertInstanceOf(Principal::class, $principal);
        self::assertSame(PrincipalType::ImpersonatedUser, $principal->type());
        self::assertSame('target_tenant_user', $principal->id());
        self::assertSame('originator_admin', $principal->claims()['originator_principal_id'] ?? null);
        self::assertSame('target_tenant_user', $principal->claims()['target_principal_id'] ?? null);
        self::assertSame('tenant:acme', $principal->claims()['impersonation_scope'] ?? null);
    }

    public function test_manager_for_remains_untyped_and_anonymous_compatible(): void
    {
        $app = new Application(sys_get_temp_dir());
        /** @var AuthorizationManagerInterface $manager */
        $manager = $app->make(AuthorizationManagerInterface::class);

        $bound = $manager->for('some-string-id');
        $reflection = new \ReflectionObject($bound);
        $principalProp = $reflection->getProperty('principal');
        $principalProp->setAccessible(true);
        $principal = $principalProp->getValue($bound);

        self::assertSame('some-string-id', $principal);
    }

    public function test_manager_check_and_cannot_use_bound_principal_context(): void
    {
        $app = new Application(sys_get_temp_dir());
        /** @var AuthorizationManager $manager */
        $manager = $app->make(AuthorizationManager::class);

        // Authority early gate off means we fall to planner (no manifest rules)
        // No manifest rules → planner returns abstain → default strategy deny
        // So check for any ability should be false (cannot = true) with default deny
        $bound = $manager->impersonate('originator_x', 'target_y', Scope::GLOBAL);

        self::assertFalse($bound->check('something.arbitrary'));
        self::assertTrue($bound->cannot('something.arbitrary'));
    }

    public function test_manager_impersonate_rejects_empty_target(): void
    {
        $app = new Application(sys_get_temp_dir());
        /** @var AuthorizationManager $manager */
        $manager = $app->make(AuthorizationManager::class);

        $this->expectException(\InvalidArgumentException::class);
        $manager->impersonate('originator', '   ');
    }
}
