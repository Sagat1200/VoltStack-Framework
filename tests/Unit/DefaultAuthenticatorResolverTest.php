<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Authenticators\PasswordAuthenticator;
use Quantum\Auth\Authenticators\SessionAuthenticator;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Identity\LocalIdentityProvider;
use Quantum\Auth\Passwords\PasswordPolicy;
use Quantum\Auth\Runtime\AuthenticationOperationContext;
use Quantum\Auth\Runtime\DefaultAuthenticatorResolver;
use Quantum\Auth\Sessions\InMemoryAuthenticationSessionRepository;
use Quantum\Config\ConfigRepository;

final class DefaultAuthenticatorResolverTest extends TestCase
{
    public function test_it_resolves_password_authenticator_for_authenticate_operation(): void
    {
        $resolver = $this->resolver();

        $resolved = $resolver->resolve(new AuthenticationOperationContext(
            operation: 'authenticate',
            request: new AuthenticationRequest(
                requestId: 'req-auth',
                attributes: [
                    'credentials' => [
                        'identifier' => 'volt@example.com',
                        'password' => 'secret-123',
                    ],
                ],
            ),
        ));

        self::assertCount(1, $resolved);
        self::assertInstanceOf(PasswordAuthenticator::class, $resolved[0]);
    }

    public function test_it_resolves_session_authenticator_for_recover_operation(): void
    {
        $resolver = $this->resolver();

        $resolved = $resolver->resolve(new AuthenticationOperationContext(
            operation: 'recover',
            request: new AuthenticationRequest(
                requestId: 'req-recover',
                attributes: ['session_id' => 'session-123'],
            ),
        ));

        self::assertCount(1, $resolved);
        self::assertInstanceOf(SessionAuthenticator::class, $resolved[0]);
    }

    private function resolver(): DefaultAuthenticatorResolver
    {
        $config = new ConfigRepository([
            'auth' => [
                'providers' => [
                    'local' => [
                        'identities' => [],
                    ],
                ],
            ],
        ]);

        return new DefaultAuthenticatorResolver(
            new SessionAuthenticator(new InMemoryAuthenticationSessionRepository()),
            new PasswordAuthenticator(
                new LocalIdentityProvider($config),
                new PasswordPolicy($config),
            ),
        );
    }
}
