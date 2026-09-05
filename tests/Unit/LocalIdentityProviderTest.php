<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Identity\GenericIdentity;
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
}
