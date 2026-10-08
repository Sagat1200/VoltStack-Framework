<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\AuthenticationServiceProvider;
use Quantum\Auth\Authenticators\TotpAuthenticator;
use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Contracts\AdaptiveRiskPolicyInterface;
use Quantum\Auth\Contracts\AssuranceContextResolverInterface;
use Quantum\Auth\Contracts\DistributedThrottleCounterInterface;
use Quantum\Auth\Contracts\OpaqueTokenRepositoryInterface;
use Quantum\Auth\Contracts\OperationAssurancePolicyInterface;
use Quantum\Auth\Contracts\RefreshTokenRotationStoreInterface;
use Quantum\Auth\Contracts\RiskAdaptivePolicyInterface;
use Quantum\Auth\Contracts\ThrottleDistributedStorageInterface;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Config\ConfigRepository;
use VoltStack\Framework\Application;

final class Bloque083G2SpDiTest extends TestCase
{
    private static function makeAppWithAuthConfig(array $authConfigOverrides = []): Application
    {
        $dir = dirname(__DIR__, 3);
        $app = new Application($dir);
        $app->instance(ConfigRepository::class, new ConfigRepository([
            'auth' => array_replace_recursive([
                'session' => ['driver' => 'memory'],
                'tokens' => ['driver' => 'memory'],
                'throttle' => [
                    'enabled' => false,
                    'distributed' => ['enabled' => false],
                ],
                'risk' => [
                    'enabled' => false,
                    'adaptive' => ['enabled' => false],
                ],
                'transaction' => ['nonce' => ['enabled' => false]],
                'assurance' => ['enabled' => false],
            ], $authConfigOverrides),
        ]));
        $app->register(AuthenticationServiceProvider::class);

        return $app;
    }

    public function test_refresh_rotation_store_resolves_same_repository_instance(): void
    {
        $app = self::makeAppWithAuthConfig();

        $repo = $app->make(OpaqueTokenRepositoryInterface::class);
        $rotationStore = $app->make(RefreshTokenRotationStoreInterface::class);

        self::assertNotNull($rotationStore);
        self::assertInstanceOf(RefreshTokenRotationStoreInterface::class, $repo);
        self::assertSame($repo, $rotationStore);
    }

    public function test_throttle_distributed_storage_default_disabled_resolves_null(): void
    {
        $app = self::makeAppWithAuthConfig();

        self::assertNull($app->make(DistributedThrottleCounterInterface::class));
        self::assertNull($app->make(ThrottleDistributedStorageInterface::class));
    }

    public function test_risk_adaptive_policy_default_disabled_resolves_null(): void
    {
        $app = self::makeAppWithAuthConfig();

        self::assertNull($app->make(AdaptiveRiskPolicyInterface::class));
        self::assertNull($app->make(RiskAdaptivePolicyInterface::class));
    }

    public function test_risk_adaptive_policy_enabled_resolves_alias_binding(): void
    {
        $app = self::makeAppWithAuthConfig([
            'risk' => [
                'enabled' => true,
                'adaptive' => [
                    'enabled' => true,
                    'step_up_threshold' => 70,
                    'deny_threshold' => 90,
                ],
            ],
        ]);

        $legacy = $app->make(AdaptiveRiskPolicyInterface::class);
        $alias = $app->make(RiskAdaptivePolicyInterface::class);

        self::assertNotNull($legacy);
        self::assertNotNull($alias);
        self::assertSame($legacy, $alias);
    }

    public function test_assurance_interfaces_default_disabled_resolve_null(): void
    {
        $app = self::makeAppWithAuthConfig();

        self::assertNull($app->make(AssuranceContextResolverInterface::class));
        self::assertNull($app->make(OperationAssurancePolicyInterface::class));
    }

    public function test_assurance_interfaces_enabled_resolve_and_evaluate_context_and_request(): void
    {
        $app = self::makeAppWithAuthConfig([
            'assurance' => ['enabled' => true],
        ]);

        $resolver = $app->make(AssuranceContextResolverInterface::class);
        $policy = $app->make(OperationAssurancePolicyInterface::class);

        self::assertNotNull($resolver);
        self::assertNotNull($policy);

        $id = new IdentityIdentifier('usr_assurance_01');
        $context = new AuthenticationContext(
            identity: new GenericIdentity($id, 'local'),
            reference: new IdentityReference($id, 'local'),
            requestId: 'req-assurance',
            method: 'password',
            attributes: [
                'assurance_value' => 55,
                'assurance_name' => 'custom_high',
            ],
        );

        $operation = new AuthenticationOperationContext(
            operation: 'step_up',
            request: new AuthenticationRequest('req-1', attributes: [
                'min_authentication_assurance' => '30',
            ]),
            currentContext: $context,
        );

        self::assertSame(
            ['current_assurance' => 55, 'current_assurance_name' => 'custom_high'],
            $resolver->resolve($operation),
        );
        self::assertSame(30, $policy->minimumAssuranceFor($operation));
    }

    public function test_totp_authenticator_resolves_when_mfa_totp_is_enabled(): void
    {
        $app = self::makeAppWithAuthConfig([
            'mfa' => [
                'enabled' => true,
                'totp' => ['enabled' => true],
            ],
        ]);

        $resolved = $app->make(TotpAuthenticator::class);

        self::assertInstanceOf(TotpAuthenticator::class, $resolved);
    }
}
