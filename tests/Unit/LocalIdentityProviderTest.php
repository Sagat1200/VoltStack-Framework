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
        self::assertNotNull($provider->passwordHashFor($identity));
        self::assertTrue(password_verify('secret-123', (string) $provider->passwordHashFor($identity)));
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
}
