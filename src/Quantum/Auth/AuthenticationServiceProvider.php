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
use Quantum\Auth\Contracts\InventoryReconcilerInterface;
use Quantum\Auth\Contracts\PasswordPolicyInterface;
use Quantum\Auth\Contracts\SessionRepositoryDriverFactoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryDriverFactoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Devices\FileTrustedDeviceRepository;
use Quantum\Auth\Devices\InMemoryTrustedDeviceRepository;
use Quantum\Auth\Devices\InventoryReconciler;
use Quantum\Auth\Identity\LocalIdentityProvider;
use Quantum\Auth\Passwords\PasswordPolicy;
use Quantum\Auth\Runtime\AuthenticationOrchestrator;
use Quantum\Auth\Runtime\DefaultAuthenticatorResolver;
use Quantum\Auth\Runtime\SessionRepositoryDriverFactory;
use Quantum\Auth\Runtime\TrustedDeviceRepositoryDriverFactory;
use Quantum\Auth\Sessions\FileAuthenticationSessionRepository;
use Quantum\Auth\Sessions\InMemoryAuthenticationSessionRepository;
use Quantum\Config\ConfigRepository;
use Quantum\HttpKernel\MiddlewareAliasRegistry;
use Quantum\Middlewares\AuthMiddleware;
use Quantum\Middlewares\GuestMiddleware;
use Quantum\Middlewares\MfaMiddleware;
use VoltStack\Framework\Application;
use VoltStack\Framework\ServiceProvider;

final class AuthenticationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AuthenticationContextAccessor::class);
        $this->app->scoped(IdentityProviderInterface::class, LocalIdentityProvider::class);
        $this->app->scoped(PasswordPolicyInterface::class, PasswordPolicy::class);
        $this->app->scoped(InventoryReconcilerInterface::class, static function (Application $app): InventoryReconcilerInterface {
            return new InventoryReconciler(
                $app->make(AuthenticationSessionRepositoryInterface::class),
                $app->make(TrustedDeviceRepositoryInterface::class),
            );
        });

        $this->app->singleton(SessionRepositoryDriverFactoryInterface::class, static function (): SessionRepositoryDriverFactoryInterface {
            return new SessionRepositoryDriverFactory();
        });

        $this->app->singleton(TrustedDeviceRepositoryDriverFactoryInterface::class, static function (): TrustedDeviceRepositoryDriverFactoryInterface {
            return new TrustedDeviceRepositoryDriverFactory();
        });

        $this->app->singleton(AuthenticationSessionRepositoryInterface::class, function (Application $app): AuthenticationSessionRepositoryInterface {
            $factory = $app->make(SessionRepositoryDriverFactoryInterface::class);
            $configuredDriver = (string) $app->config('auth.session.driver', 'memory');
            $configuredDriver = strtolower(trim($configuredDriver));
            $retention = $app->config('auth.session.cleanup.tombstone_retention', 604800);
            $retention = is_numeric($retention) ? max(0, (int) $retention) : 604800;

            if (! $factory->hasDriver($configuredDriver)) {
                $configuredDriver = 'memory';
            }

            $storagePath = null;
            if ($configuredDriver === 'file') {
                try {
                    $storagePath = $app->storagePath('framework/auth/sessions');
                } catch (\Throwable) {
                    $storagePath = null;
                }
            }

            return $factory->make($configuredDriver, [
                'storage_path' => $storagePath,
                'recovery_retention_seconds' => $retention,
            ]);
        });

        $this->app->singleton(TrustedDeviceRepositoryInterface::class, function (Application $app): TrustedDeviceRepositoryInterface {
            $factory = $app->make(TrustedDeviceRepositoryDriverFactoryInterface::class);
            $driver = $app->config('auth.trusted_devices.driver');
            $configuredDriver = is_string($driver) && trim($driver) !== ''
                ? strtolower(trim($driver))
                : strtolower((string) $app->config('auth.session.driver', 'memory'));

            if (! $factory->hasDriver($configuredDriver)) {
                $configuredDriver = 'memory';
            }

            $storagePath = null;
            if ($configuredDriver === 'file') {
                try {
                    $storagePath = $app->storagePath('framework/auth/trusted-devices');
                } catch (\Throwable) {
                    $storagePath = null;
                }
            }

            return $factory->make($configuredDriver, [
                'storage_path' => $storagePath,
            ]);
        });

        $this->app->scoped(AuthenticatorInterface::class, fn(Application $app) => new PasswordAuthenticator(
            $app->make(IdentityProviderInterface::class),
            $app->make(PasswordPolicyInterface::class),
            $app->make(TrustedDeviceRepositoryInterface::class),
            $app->make(ConfigRepository::class),
        ));
        $this->app->scoped(SessionAuthenticator::class);
        $this->app->scoped(AuthenticatorResolverInterface::class, DefaultAuthenticatorResolver::class);
        $this->app->scoped(AuthenticationOrchestratorInterface::class, AuthenticationOrchestrator::class);
        $this->app->scoped(AuthManager::class, fn(Application $app) => new AuthManager(
            $app->make(AuthenticationContextAccessor::class),
            $app->make(AuthenticationOrchestratorInterface::class),
            $app->make(AuthenticationSessionRepositoryInterface::class),
            $app->make(TrustedDeviceRepositoryInterface::class),
            $app->make(ConfigRepository::class),
        ));
        $this->app->scoped(AuthenticationManagerInterface::class, fn(Application $app) => $app->make(AuthManager::class));
        $this->app->scoped(AuthMiddleware::class);
        $this->app->scoped(GuestMiddleware::class);
        $this->app->scoped(MfaMiddleware::class);

        // The alias must exist before route registration so fluent and attribute routes can resolve it.
        $this->app->make(MiddlewareAliasRegistry::class)->alias('auth', AuthMiddleware::class);
        $this->app->make(MiddlewareAliasRegistry::class)->alias('guest', GuestMiddleware::class);
        $this->app->make(MiddlewareAliasRegistry::class)->alias('mfa', MfaMiddleware::class);
    }
}
