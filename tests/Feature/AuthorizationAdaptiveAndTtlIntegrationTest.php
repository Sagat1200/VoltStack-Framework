<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\AuthorizationServiceProvider;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Contracts\AuthorizationManagerInterface;
use Quantum\Authorization\Contracts\AuthorityAdministrationInterface;
use Quantum\Authorization\Contracts\DelegationAdministrationInterface;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Config\ConfigRepository;
use VoltStack\Framework\Application;

final class AuthorizationAdaptiveAndTtlIntegrationTest extends TestCase
{
    private static function buildApp(bool $adaptive = true): Application
    {
        $app = new Application(sys_get_temp_dir());
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->set('authorization.delegation.enabled', true);
        $config->set('authorization.authority.evaluate_requirements_concretely', true);
        if ($adaptive) {
            $config->set('authorization.adaptive_access.enabled', true);
            $config->set('authorization.adaptive_access.deny_threshold', 80);
            $config->set('authorization.adaptive_access.allow_threshold', 10);
            $config->set('authorization.adaptive_access.policies', [
                'operation:sales.invoices.issue' => [
                    'deny_threshold' => 30,
                ],
            ]);
        }

        $provider = new AuthorizationServiceProvider($app);
        $provider->register();

        return $app;
    }

    public function test_adaptive_operation_policy_applies_via_manager(): void
    {
        $app = self::buildApp();

        /** @var AuthorizationManagerInterface $manager */
        $manager = $app->make(AuthorizationManagerInterface::class);

        // Grant direct permission so the only gate is adaptive.
        /** @var AuthorityAdministrationInterface $admin */
        $admin = $app->make(AuthorityAdministrationInterface::class);
        $admin->grantPermission('u_risky', Permission::from('sales.invoices.issue'), Scope::GLOBAL);

        // Risk score 40 >= operation policy deny_threshold 30 → DENY.
        $context = AuthorizationContext::empty()->withAttributes(['auth_risk_score' => 40]);
        $result = $manager->for('u_risky')->decide('sales.invoices.issue', null, $context);

        self::assertInstanceOf(DecisionResult::class, $result);
        self::assertTrue($result->isDenied());
        self::assertSame('operation:sales.invoices.issue', $result->metadata()['adaptive_policy_dimension'] ?? null);
    }

    public function test_adaptive_allow_threshold_shortcuts_via_manager(): void
    {
        $app = self::buildApp();

        /** @var AuthorizationManagerInterface $manager */
        $manager = $app->make(AuthorizationManagerInterface::class);

        // Risk score 5 <= allow_threshold 10 → ALLOW shortcut.
        $context = AuthorizationContext::empty()->withAttributes(['auth_risk_score' => 5]);
        $result = $manager->for('u_low')->decide('sales.invoices.issue', null, $context);

        self::assertInstanceOf(DecisionResult::class, $result);
        self::assertTrue($result->isAllowed());
        self::assertSame('allow', $result->metadata()['adaptive_access_action'] ?? null);
    }

    public function test_expired_delegation_is_ignored_via_manager(): void
    {
        $app = self::buildApp(adaptive: false);

        $grantorId = 'grantor-finance';
        $trusteeId = 'trustee-assistant';

        /** @var DelegationAdministrationInterface $delegationAdmin */
        $delegationAdmin = $app->make(DelegationAdministrationInterface::class);
        $delegationAdmin->grantDelegation(
            trusteeId: $trusteeId,
            grantorId: $grantorId,
            grant: Permission::from('sales.invoices.issue'),
            scope: 'tenant:contoso',
            expiresAt: '-2 hours',
        );

        /** @var AuthorizationManagerInterface $manager */
        $manager = $app->make(AuthorizationManagerInterface::class);

        $bound = $manager->impersonate($trusteeId, $grantorId, 'tenant:contoso');
        $context = AuthorizationContext::empty()->withAttributes([
            'authorization.metadata.requirements' => [
                ['ability' => 'sales.invoices.issue', 'scope' => 'tenant:contoso', 'principal' => null, 'condition' => null, 'effect' => 'permit'],
            ],
            'authorization.metadata.matched_requirements' => [
                ['ability' => 'sales.invoices.issue', 'scope' => 'tenant:contoso', 'principal' => null, 'condition' => null, 'effect' => 'permit'],
            ],
        ]);
        $result = $bound->decide('sales.invoices.issue', null, $context);

        self::assertInstanceOf(DecisionResult::class, $result);
        // Expired delegation must not authorize → deny.
        self::assertTrue($result->isDenied());
    }

    public function test_active_delegation_authorizes_via_manager(): void
    {
        $app = self::buildApp(adaptive: false);

        $grantorId = 'grantor-finance-active';
        $trusteeId = 'trustee-assistant-active';

        /** @var DelegationAdministrationInterface $delegationAdmin */
        $delegationAdmin = $app->make(DelegationAdministrationInterface::class);
        $delegationAdmin->grantDelegation(
            trusteeId: $trusteeId,
            grantorId: $grantorId,
            grant: Permission::from('sales.invoices.issue'),
            scope: 'tenant:contoso',
            expiresAt: '+2 hours',
        );

        /** @var AuthorizationManagerInterface $manager */
        $manager = $app->make(AuthorizationManagerInterface::class);

        $bound = $manager->impersonate($trusteeId, $grantorId, 'tenant:contoso');
        $context = AuthorizationContext::empty()->withAttributes([
            'authorization.metadata.requirements' => [
                ['ability' => 'sales.invoices.issue', 'scope' => 'tenant:contoso', 'principal' => null, 'condition' => null, 'effect' => 'permit'],
            ],
            'authorization.metadata.matched_requirements' => [
                ['ability' => 'sales.invoices.issue', 'scope' => 'tenant:contoso', 'principal' => null, 'condition' => null, 'effect' => 'permit'],
            ],
        ]);
        $result = $bound->decide('sales.invoices.issue', null, $context);

        self::assertInstanceOf(DecisionResult::class, $result);
        self::assertTrue($result->isAllowed());
        self::assertTrue((bool) ($result->metadata()['delegation_granted'] ?? false));
    }
}
