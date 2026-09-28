<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Authenticators\PasswordAuthenticator;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\PasswordPolicyInterface;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentitySecurityState;
use Quantum\Auth\Identity\LocalIdentityProvider;
use Quantum\Auth\Decisions\AuthenticationDecisionStatus;
use Quantum\Auth\Passwords\PasswordRotationReceipt;
use Quantum\Auth\Passwords\RetentionTieredEnforcer;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Config\ConfigRepository;

final class BloqueATest extends TestCase
{
    public function test_bulk_invalidate_by_identifier_prefix_returns_count_of_invalidated(): void
    {
        $config = new ConfigRepository([
            'auth' => [
                'providers' => [
                    'local' => [
                        'identities' => [
                            ['id' => 1, 'identifier' => 'admin_alice@test', 'password_hash' => password_hash('p1', PASSWORD_DEFAULT)],
                            ['id' => 2, 'identifier' => 'admin_bob@test', 'password_hash' => password_hash('p2', PASSWORD_DEFAULT)],
                            ['id' => 3, 'identifier' => 'user_charlie@test', 'password_hash' => password_hash('p3', PASSWORD_DEFAULT)],
                            ['id' => 4, 'email' => 'admin_dave@test', 'password_hash' => password_hash('p4', PASSWORD_DEFAULT)],
                            ['id' => 5, 'identifier' => 'support_eve@test', 'password_hash' => password_hash('p5', PASSWORD_DEFAULT)],
                        ],
                    ],
                ],
            ],
        ]);

        $provider = new LocalIdentityProvider($config);
        $count = $provider->bulkInvalidateByIdentifierPrefix('admin_', 'bulk_invalidate_test');

        self::assertSame(3, $count);
        $alice = $provider->findByIdentifier('admin_alice@test');
        $bob = $provider->findByIdentifier('admin_bob@test');
        $charlie = $provider->findByIdentifier('user_charlie@test');
        $dave = $provider->findByIdentifier('admin_dave@test');
        self::assertInstanceOf(GenericIdentity::class, $alice);
        self::assertInstanceOf(GenericIdentity::class, $bob);
        self::assertInstanceOf(GenericIdentity::class, $charlie);
        self::assertInstanceOf(GenericIdentity::class, $dave);
        self::assertSame(IdentitySecurityState::Suspended, $provider->securityStateFor($alice));
        self::assertSame(IdentitySecurityState::Suspended, $provider->securityStateFor($bob));
        self::assertSame(IdentitySecurityState::Suspended, $provider->securityStateFor($dave));
        self::assertSame(IdentitySecurityState::Active, $provider->securityStateFor($charlie));
    }

    public function test_rotation_receipt_save_persists_in_config_array(): void
    {
        $config = new ConfigRepository([
            'auth' => [
                'providers' => [
                    'local' => [
                        'identities' => [
                            ['id' => 'receipt_test_id', 'identifier' => 'receipt@test', 'password_hash' => password_hash('p1', PASSWORD_DEFAULT)],
                        ],
                    ],
                ],
            ],
        ]);

        $provider = new LocalIdentityProvider($config);
        $identity = $provider->findByIdentifier('receipt@test');
        self::assertInstanceOf(GenericIdentity::class, $identity);

        $result = $provider->saveRotationReceipt(
            identity: $identity,
            previousHash: 'prev_abc',
            newHash: 'new_xyz',
            rotatedAt: 1700000000,
            rotatedByActorSessionPublicId: 'spub_receipt_test_123',
            reason: 'unit_test_reason',
        );

        self::assertTrue($result);
        $stored = $config->get('auth.providers.local.rotation_receipts', []);
        self::assertIsArray($stored);
        self::assertCount(1, $stored);
        $receipt = PasswordRotationReceipt::fromArray($stored[0]);
        self::assertSame('receipt_test_id', $receipt->identityId);
        self::assertSame('prev_abc', $receipt->previousHash);
        self::assertSame('new_xyz', $receipt->newHash);
        self::assertSame(1700000000, $receipt->rotatedAt);
        self::assertSame('spub_receipt_test_123', $receipt->rotatedByActorSessionPublicId);
        self::assertSame('unit_test_reason', $receipt->reason);
    }

    public function test_retention_tier_for_picks_entry_value_then_config_default(): void
    {
        $config = new ConfigRepository([
            'auth' => [
                'password' => [
                    'retention' => [
                        'default_tier' => 'low',
                    ],
                ],
                'providers' => [
                    'local' => [
                        'identities' => [
                            ['id' => 1, 'identifier' => 'high@test', 'password_hash' => password_hash('p1', PASSWORD_DEFAULT), 'retention_tier' => 'HIGH'],
                            ['id' => 2, 'identifier' => 'default@test', 'password_hash' => password_hash('p2', PASSWORD_DEFAULT)],
                            ['id' => 3, 'identifier' => 'medium@test', 'password_hash' => password_hash('p3', PASSWORD_DEFAULT), 'retention_tier' => 'medium'],
                        ],
                    ],
                ],
            ],
        ]);

        $provider = new LocalIdentityProvider($config);
        $high = $provider->findByIdentifier('high@test');
        $default = $provider->findByIdentifier('default@test');
        $medium = $provider->findByIdentifier('medium@test');
        self::assertNotNull($high);
        self::assertNotNull($default);
        self::assertNotNull($medium);

        self::assertSame('high', $provider->retentionTierFor($high));
        self::assertSame('low', $provider->retentionTierFor($default));
        self::assertSame('medium', $provider->retentionTierFor($medium));
    }

    public function test_retention_high_tier_requires_immediate_expiry_for_old_password(): void
    {
        $enforcer = new RetentionTieredEnforcer();
        $now = time();
        $lifecycle = [
            'password_created_at' => $now - (100 * 86400),
            'password_last_rotated_at' => $now - (100 * 86400),
        ];

        self::assertFalse($enforcer->requiresImmediateExpiry($lifecycle, 'low', $now));
        self::assertTrue($enforcer->requiresImmediateExpiry($lifecycle, 'high', $now));
        self::assertFalse($enforcer->requiresImmediateExpiry($lifecycle, 'medium', $now));
    }

    public function test_credential_strength_check_long_complex_password_passes(): void
    {
        $config = new ConfigRepository([
            'auth' => ['providers' => ['local' => ['identities' => []]]],
        ]);
        $provider = new LocalIdentityProvider($config);

        $result = $provider->credentialStrengthCheck('C0mpl3x!P@ssw0rd#2025-Long');

        self::assertIsArray($result);
        self::assertArrayHasKey('score', $result);
        self::assertArrayHasKey('issues', $result);
        self::assertArrayHasKey('passes', $result);
        self::assertTrue($result['passes']);
        self::assertGreaterThanOrEqual(70, $result['score']);
        self::assertSame([], $result['issues']);
    }

    public function test_credential_strength_check_common_short_password_fails_with_issues(): void
    {
        $config = new ConfigRepository([
            'auth' => ['providers' => ['local' => ['identities' => []]]],
        ]);
        $provider = new LocalIdentityProvider($config);

        $result = $provider->credentialStrengthCheck('password');

        self::assertFalse($result['passes']);
        self::assertLessThanOrEqual(15, $result['score']);
        self::assertContains('common_password', $result['issues']);
    }

    public function test_multi_dim_lockout_thresholds_returns_four_keys_with_defaults(): void
    {
        $config = new ConfigRepository([
            'auth' => ['providers' => ['local' => ['identities' => []]]],
        ]);
        $provider = new LocalIdentityProvider($config);
        $thresholds = $provider->multiDimLockoutThresholds();

        self::assertIsArray($thresholds);
        self::assertArrayHasKey('by_identifier', $thresholds);
        self::assertArrayHasKey('by_device_ref', $thresholds);
        self::assertArrayHasKey('by_ip_prefix', $thresholds);
        self::assertArrayHasKey('window_seconds', $thresholds);
        self::assertSame(10, $thresholds['by_identifier']);
        self::assertSame(15, $thresholds['by_device_ref']);
        self::assertSame(25, $thresholds['by_ip_prefix']);
        self::assertSame(900, $thresholds['window_seconds']);
    }

    public function test_multi_dim_lockout_thresholds_picks_overrides_from_config(): void
    {
        $config = new ConfigRepository([
            'auth' => [
                'password' => [
                    'lockout' => [
                        'multi_dim' => [
                            'by_identifier' => 5,
                            'by_device_ref' => 7,
                            'by_ip_prefix' => 12,
                            'window_seconds' => 300,
                        ],
                    ],
                ],
                'providers' => ['local' => ['identities' => []]],
            ],
        ]);
        $provider = new LocalIdentityProvider($config);
        $thresholds = $provider->multiDimLockoutThresholds();

        self::assertSame(5, $thresholds['by_identifier']);
        self::assertSame(7, $thresholds['by_device_ref']);
        self::assertSame(12, $thresholds['by_ip_prefix']);
        self::assertSame(300, $thresholds['window_seconds']);
    }

    public function test_retention_tier_high_triggers_password_retention_expired_decision_in_authenticator(): void
    {
        $daysAgo = 100 * 86400;
        $oldPasswordCreated = time() - $daysAgo;
        $config = new ConfigRepository([
            'auth' => [
                'providers' => [
                    'local' => [
                        'identities' => [
                            [
                                'id' => 'ret_high',
                                'identifier' => 'retention_high@test',
                                'password_hash' => password_hash('Valid-Pass-123!', PASSWORD_DEFAULT),
                                'password_created_at' => $oldPasswordCreated,
                                'password_last_rotated_at' => $oldPasswordCreated,
                                'retention_tier' => 'high',
                                'type' => 'user',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $provider = new LocalIdentityProvider($config);
        $policyMock = $this->createMock(PasswordPolicyInterface::class);
        $policyMock->method('verify')->willReturn(true);
        $policyMock->method('needsRehash')->willReturn(false);
        $policyMock->method('isExpired')->willReturn(false);
        $policyMock->method('needsRotation')->willReturn(false);
        $policyMock->method('checkAgainstHistory')->willReturn(true);

        $authenticator = new PasswordAuthenticator($provider, $policyMock, trustedDevices: new \Quantum\Auth\Devices\InMemoryTrustedDeviceRepository(), config: $config);

        $request = new AuthenticationRequest(
            requestId: 'retention-tier-high-' . bin2hex(random_bytes(4)),
            transport: 'runtime',
            attributes: [
                'credentials' => ['identifier' => 'retention_high@test', 'password' => 'Valid-Pass-123!'],
            ],
        );
        $ctx = new AuthenticationOperationContext('authenticate', $request);
        $decision = $authenticator->authenticate($ctx);

        self::assertSame(AuthenticationDecisionStatus::Rejected, $decision->status);
        self::assertSame('password_retention_expired', $decision->metadata['reason'] ?? null);
        self::assertSame('high', $decision->metadata['retention_tier'] ?? null);
        self::assertSame(90, $decision->metadata['retention_days'] ?? null);
    }

    public function test_non_governance_provider_skips_all_gates_without_regression(): void
    {
        $nonGovernanceProvider = new class implements IdentityProviderInterface, \Quantum\Auth\Contracts\PasswordRehashingIdentityProviderInterface, \Quantum\Auth\Contracts\PasswordLifecycleAwareProviderInterface {
            public function findByIdentifier(string $identifier): ?\Quantum\Auth\Identity\IdentityInterface
            {
                if ($identifier !== 'ngp@test') {
                    return null;
                }
                return new GenericIdentity(
                    identifier: new IdentityIdentifier('ngp_id'),
                    type: 'user',
                    attributes: ['_provider_identifier_value' => 'ngp@test'],
                );
            }

            public function passwordHashFor(\Quantum\Auth\Identity\IdentityInterface $identity): ?string
            {
                return password_hash('Valid-Pass-123!', PASSWORD_DEFAULT);
            }

            public function securityStateFor(\Quantum\Auth\Identity\IdentityInterface $identity): IdentitySecurityState
            {
                return IdentitySecurityState::Active;
            }

            public function upgradePasswordHash(\Quantum\Auth\Identity\IdentityInterface $identity, string $passwordHash): bool
            {
                return false;
            }

            public function passwordLifecycleMetadataFor(\Quantum\Auth\Identity\IdentityInterface $identity): array
            {
                return [
                    'password_created_at' => time() - 10,
                    'password_last_rotated_at' => null,
                    'password_expires_at' => null,
                    'password_rotation_history' => [],
                    'failed_attempts' => 0,
                    'lockout_until' => null,
                    'last_failed_attempt_at' => null,
                    'security_state' => IdentitySecurityState::Active->value,
                    'security_state_reason' => null,
                ];
            }
        };

        $policyMock = $this->createMock(PasswordPolicyInterface::class);
        $policyMock->method('verify')->willReturn(true);
        $policyMock->method('needsRehash')->willReturn(false);
        $policyMock->method('isExpired')->willReturn(false);
        $policyMock->method('needsRotation')->willReturn(false);
        $policyMock->method('checkAgainstHistory')->willReturn(true);

        $authenticator = new PasswordAuthenticator($nonGovernanceProvider, $policyMock);

        $request = new AuthenticationRequest(
            requestId: 'non-governance-provider-' . bin2hex(random_bytes(4)),
            transport: 'runtime',
            attributes: [
                'credentials' => ['identifier' => 'ngp@test', 'password' => 'Valid-Pass-123!'],
            ],
        );
        $ctx = new AuthenticationOperationContext('authenticate', $request);
        $decision = $authenticator->authenticate($ctx);

        self::assertTrue($decision->isAuthenticated());
        self::assertArrayNotHasKey('retention_tier', $decision->metadata);
        self::assertArrayNotHasKey('credential_strength_score', $decision->metadata);
        self::assertArrayNotHasKey('credential_strength_passes', $decision->metadata);
    }
}
