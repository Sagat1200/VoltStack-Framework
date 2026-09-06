<?php

declare(strict_types=1);

namespace Quantum\Auth;

use Quantum\Auth\Authenticators\PasswordAuthenticator;
use Quantum\Auth\Authenticators\SessionAuthenticator;
use Quantum\Auth\Context\AuthenticationContextAccessor;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Auth\Contracts\AuthenticationOrchestratorInterface;
use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\AuthenticatorResolverInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\PasswordPolicyInterface;
use Quantum\Auth\Identity\LocalIdentityProvider;
use Quantum\Auth\Passwords\PasswordPolicy;
use Quantum\Auth\Runtime\AuthenticationOrchestrator;
use Quantum\Auth\Runtime\DefaultAuthenticatorResolver;
use Quantum\Auth\Sessions\FileAuthenticationSessionRepository;
use Quantum\Auth\Sessions\InMemoryAuthenticationSessionRepository;
use Quantum\Config\ConfigRepository;
use Quantum\HttpKernel\MiddlewareAliasRegistry;
use Quantum\Middlewares\AuthMiddleware;
use Quantum\Middlewares\GuestMiddleware;
use VoltStack\Framework\Application;
use VoltStack\Framework\ServiceProvider;

final class AuthenticationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AuthenticationContextAccessor::class);
        $this->app->scoped(IdentityProviderInterface::class, LocalIdentityProvider::class);
        $this->app->scoped(PasswordPolicyInterface::class, PasswordPolicy::class);
        $this->app->scoped(AuthenticatorInterface::class, PasswordAuthenticator::class);
        $this->app->singleton(AuthenticationSessionRepositoryInterface::class, function (Application $app): AuthenticationSessionRepositoryInterface {
            $driver = (string) $app->config('auth.session.driver', 'memory');

            return match (strtolower(trim($driver))) {
                'file' => new FileAuthenticationSessionRepository(
                    $app->storagePath('framework/auth/sessions'),
                ),
                default => new InMemoryAuthenticationSessionRepository(),
            };
        });
        $this->app->scoped(SessionAuthenticator::class);
        $this->app->scoped(AuthenticatorResolverInterface::class, DefaultAuthenticatorResolver::class);
        $this->app->scoped(AuthenticationOrchestratorInterface::class, AuthenticationOrchestrator::class);
        $this->app->scoped(AuthManager::class, fn(Application $app) => new AuthManager(
            $app->make(AuthenticationContextAccessor::class),
            $app->make(AuthenticationOrchestratorInterface::class),
            $app->make(AuthenticationSessionRepositoryInterface::class),
            $app->make(ConfigRepository::class),
        ));
        $this->app->scoped(AuthenticationManagerInterface::class, fn(Application $app) => $app->make(AuthManager::class));
        $this->app->scoped(AuthMiddleware::class);
        $this->app->scoped(GuestMiddleware::class);

        // The alias must exist before route registration so fluent and attribute routes can resolve it.
        $this->app->make(MiddlewareAliasRegistry::class)->alias('auth', AuthMiddleware::class);
        $this->app->make(MiddlewareAliasRegistry::class)->alias('guest', GuestMiddleware::class);
    }
}