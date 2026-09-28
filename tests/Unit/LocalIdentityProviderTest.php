<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentitySecurityState;
use Quantum\Auth\Identity\LocalIdentityProvider;
use Quantum\Config\ConfigRepository;

final class LocalIdentityProviderTest extends TestCase
{
    public function test_it_resolves_identity_and_password_hash_from_local_config(): void
    {
        $config = new ConfigRepository([
            'auth' => [
                'providers' => [
                    'local' => [
                        'identities' => [
                            [
                                'id' => 7,
                                'identifier' => 'volt@example.com',
                                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                                'mfa_code' => '654321',
                                'type' => 'user',
                                'name' => 'Volt User',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $provider = new LocalIdentityProvider($config);
        $identity = $provider->findByIdentifier('volt@example.com');

        self::assertInstanceOf(GenericIdentity::class, $identity);
        self::assertSame('7', (string) $identity->identifier());
        self::assertSame('user', $identity->type());
        self::assertSame('Volt User', $identity->attributes['name'] ?? null);
        self::assertArrayNotHasKey('mfa_code', $identity->attributes);
        self::assertNotNull($provider->passwordHashFor($identity));
        self::assertTrue(password_verify('secret-123', (string) $provider->passwordHashFor($identity)));
        self::assertTrue($provider->supportsSecondFactor($identity));
        self::assertFalse($provider->requiresSecondFactor($identity));
        self::assertTrue($provider->verifySecondFactor($identity, '654321'));
        self::assertFalse($provider->verifySecondFactor($identity, '000000'));
    }

    public function test_it_returns_null_when_identifier_is_missing(): void
    {
        $provider = new LocalIdentityProvider(new ConfigRepository());

        self::assertNull($provider->findByIdentifier('missing@example.com'));
    }

    public function test_it_resolves_identity_security_state(): void
    {
        $config = new ConfigRepository([
            'auth' => [
                'providers' => [
                    'local' => [
                        'identities' => [
                            [
                                'id' => 9,
                                'identifier' => 'disabled@example.com',
                                'password_hash' => password_hash('secret-123', PASSWORD_DEFAULT),
                                'security_state' => 'disabled',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $provider = new LocalIdentityProvider($config);
        $identity = $provider->findByIdentifier('disabled@example.com');

        self::assertInstanceOf(GenericIdentity::class, $identity);
        self::assertSame(IdentitySecurityState::Disabled, $provider->securityStateFor($identity));
    }

    public function test_it_can_persist_upgraded_password_hashes_to_the_configured_storage_file(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-local-provider-' . uniqid('', true);
        $storagePath = $directory . DIRECTORY_SEPARATOR . 'identities.json';
        $originalHash = password_hash('secret-123', PASSWORD_BCRYPT, ['cost' => 4]);
        $upgradedHash = password_hash('secret-123', PASSWORD_BCRYPT, ['cost' => 10]);

        if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            self::fail(sprintf('Unable to create [%s].', $directory));
        }

        file_put_contents($storagePath, json_encode([
            'identities' => [
                [
                    'id' => 12,
                    'identifier' => 'rehash@example.com',
                    'password_hash' => $originalHash,
                    'type' => 'user',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            $config = new ConfigRepository([
                'auth' => [
                    'providers' => [
                        'local' => [
                            'storage_path' => $storagePath,
                            'identities' => [],
                        ],
                    ],
                ],
            ]);

            $provider = new LocalIdentityProvider($config);
            $identity = $provider->findByIdentifier('rehash@example.com');

            self::assertInstanceOf(GenericIdentity::class, $identity);
            self::assertTrue($provider->upgradePasswordHash($identity, $upgradedHash));

            $stored = json_decode((string) file_get_contents($storagePath), true, 512, JSON_THROW_ON_ERROR);
            $storedHash = $stored['identities'][0]['password_hash'] ?? null;

            self::assertIsString($storedHash);
            self::assertTrue(password_verify('secret-123', $storedHash));
            self::assertNotSame($originalHash, $storedHash);
        } finally {
            @unlink($storagePath);
            @rmdir($directory);
        }
    }

    public function test_it_persists_password_rotation_history_and_truncates_to_configured_depth(): void
    {
        $directory = sys_get_temp_dir() . '/volt-lidp-history-' . bin2hex(random_bytes(5));
        @mkdir($directory);
        $storagePath = $directory . '/identities.json';
        $initialHash = password_hash('pass-v1', PASSWORD_DEFAULT);
        file_put_contents($storagePath, json_encode([
            'identities' => [
                [
                    'id' => 91,
                    'identifier' => 'history@example.com',
                    'password_hash' => $initialHash,
                    'type' => 'user',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            $config = new ConfigRepository([
                'auth' => [
                    'password' => [
                        'rotation_history_depth' => 3,
                    ],
                    'providers' => [
                        'local' => [
                            'storage_path' => $storagePath,
                            'identities' => [],
                        ],
                    ],
                ],
            ]);

            $provider = new LocalIdentityProvider($config);
            $identity = $provider->findByIdentifier('history@example.com');
            self::assertInstanceOf(GenericIdentity::class, $identity);

            $hashV2 = password_hash('pass-v2', PASSWORD_DEFAULT);
            $hashV3 = password_hash('pass-v3', PASSWORD_DEFAULT);
            $hashV4 = password_hash('pass-v4', PASSWORD_DEFAULT);
            $hashV5 = password_hash('pass-v5', PASSWORD_DEFAULT);

            self::assertTrue($provider->upgradePasswordHash($identity, $hashV2));
            $meta1 = $provider->passwordLifecycleMetadataFor($identity);
            self::assertCount(1, $meta1['password_rotation_history']);

            $identity = $provider->findByIdentifier('history@example.com');
            self::assertTrue($provider->upgradePasswordHash($identity, $hashV3));
            $identity = $provider->findByIdentifier('history@example.com');
            self::assertTrue($provider->upgradePasswordHash($identity, $hashV4));
            $identity = $provider->findByIdentifier('history@example.com');
            self::assertTrue($provider->upgradePasswordHash($identity, $hashV5));

            $final = $provider->passwordLifecycleMetadataFor($identity);
            $history = $final['password_rotation_history'];

            self::assertCount(3, $history);
            self::assertTrue(password_verify('pass-v2', $history[0]));
            self::assertTrue(password_verify('pass-v3', $history[1]));
            self::assertTrue(password_verify('pass-v4', $history[2]));
        } finally {
            @unlink($storagePath);
            @rmdir($directory);
        }
    }

    public function test_it_records_and_clears_failed_authentication_attempts(): void
    {
        $directory = sys_get_temp_dir() . '/volt-lidp-fails-' . bin2hex(random_bytes(5));
        @mkdir($directory);
        $storagePath = $directory . '/identities.json';
        file_put_contents($storagePath, json_encode([
            'identities' => [
                [
                    'id' => 92,
                    'identifier' => 'fails@example.com',
                    'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
                    'type' => 'user',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            $config = new ConfigRepository([
                'auth' => [
                    'password' => [
                        'lockout' => [
                            'attempts_threshold' => 3,
                            'window_seconds' => 300,
                        ],
                    ],
                    'providers' => [
                        'local' => [
                            'storage_path' => $storagePath,
                            'identities' => [],
                        ],
                    ],
                ],
            ]);

            $provider = new LocalIdentityProvider($config);
            $identity = $provider->findByIdentifier('fails@example.com');
            self::assertInstanceOf(GenericIdentity::class, $identity);

            self::assertFalse($provider->isLockedOut($identity));
            $provider->recordFailedAuthentication($identity);
            $provider->recordFailedAuthentication($identity);

            $metaAfter = $provider->passwordLifecycleMetadataFor($identity);
            self::assertSame(2, $metaAfter['failed_attempts']);
            self::assertFalse($provider->isLockedOut($identity));

            $provider->recordFailedAuthentication($identity);
            self::assertTrue($provider->isLockedOut($identity));

            $provider->clearFailedAuthentication($identity);
            $metaCleared = $provider->passwordLifecycleMetadataFor($identity);
            self::assertSame(0, $metaCleared['failed_attempts']);
            self::assertNull($metaCleared['lockout_until']);
            self::assertFalse($provider->isLockedOut($identity));
        } finally {
            @unlink($storagePath);
            @rmdir($directory);
        }
    }

    public function test_it_can_unlock_a_temporarily_locked_identity(): void
    {
        $config = new ConfigRepository([
            'auth' => [
                'password' => [
                    'lockout' => [
                        'attempts_threshold' => 1,
                        'window_seconds' => 120,
                    ],
                ],
                'providers' => [
                    'local' => [
                        'identities' => [
                            [
                                'id' => 93,
                                'identifier' => 'unlock@example.com',
                                'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
                                'type' => 'user',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $provider = new LocalIdentityProvider($config);
        $identity = $provider->findByIdentifier('unlock@example.com');
        self::assertInstanceOf(GenericIdentity::class, $identity);

        $provider->recordFailedAuthentication($identity);
        self::assertTrue($provider->isLockedOut($identity));

        $result = $provider->unlock($identity);
        self::assertTrue($result);
        self::assertFalse($provider->isLockedOut($identity));

        $meta = $provider->passwordLifecycleMetadataFor($identity);
        self::assertSame(0, $meta['failed_attempts']);
        self::assertNull($meta['lockout_until']);
    }

    public function test_it_can_update_identity_security_state(): void
    {
        $config = new ConfigRepository([
            'auth' => [
                'providers' => [
                    'local' => [
                        'identities' => [
                            [
                                'id' => 94,
                                'identifier' => 'state@example.com',
                                'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
                                'security_state' => 'active',
                                'type' => 'user',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $provider = new LocalIdentityProvider($config);
        $identity = $provider->findByIdentifier('state@example.com');
        self::assertInstanceOf(GenericIdentity::class, $identity);

        self::assertSame(IdentitySecurityState::Active, $provider->securityStateFor($identity));

        $provider->updateSecurityState($identity, IdentitySecurityState::Suspended, 'policy-review');
        self::assertSame(IdentitySecurityState::Suspended, $provider->securityStateFor($identity));

        $meta = $provider->passwordLifecycleMetadataFor($identity);
        self::assertSame(IdentitySecurityState::Suspended->value, $meta['security_state']);
        self::assertSame('policy-review', $meta['security_state_reason']);

        $provider->updateSecurityState($identity, IdentitySecurityState::Locked, 'admin-lock');
        self::assertSame(IdentitySecurityState::Locked, $provider->securityStateFor($identity));
    }

    public function test_it_can_toggle_second_factor_required_flag(): void
    {
        $config = new ConfigRepository([
            'auth' => [
                'providers' => [
                    'local' => [
                        'identities' => [
                            [
                                'id' => 95,
                                'identifier' => 'mfa-toggle@example.com',
                                'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
                                'type' => 'user',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $provider = new LocalIdentityProvider($config);
        $identity = $provider->findByIdentifier('mfa-toggle@example.com');
        self::assertInstanceOf(GenericIdentity::class, $identity);
        self::assertFalse($provider->requiresSecondFactor($identity));

        $provider->setSecondFactorRequired($identity, true);
        self::assertTrue($provider->requiresSecondFactor($identity));

        $provider->setSecondFactorRequired($identity, false);
        self::assertFalse($provider->requiresSecondFactor($identity));
    }

    public function test_it_exposes_default_password_lifecycle_metadata_for_new_identity(): void
    {
        $config = new ConfigRepository([
            'auth' => [
                'providers' => [
                    'local' => [
                        'identities' => [
                            [
                                'id' => 96,
                                'identifier' => 'fresh@example.com',
                                'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
                                'type' => 'user',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $provider = new LocalIdentityProvider($config);
        $identity = $provider->findByIdentifier('fresh@example.com');
        self::assertInstanceOf(GenericIdentity::class, $identity);

        $meta = $provider->passwordLifecycleMetadataFor($identity);

        self::assertSame(0, $meta['password_created_at']);
        self::assertNull($meta['password_expires_at']);
        self::assertSame(0, $meta['password_last_rotated_at']);
        self::assertSame([], $meta['password_rotation_history']);
        self::assertSame(0, $meta['failed_attempts']);
        self::assertNull($meta['lockout_until']);
        self::assertSame(IdentitySecurityState::Active->value, $meta['security_state']);
    }
}
