<?php

declare(strict_types=1);

namespace Quantum\Auth;

use Quantum\Auth\Authenticators\BearerAuthenticator;
use Quantum\Auth\Authenticators\OidcAuthenticator;
use Quantum\Auth\Authenticators\PasswordAuthenticator;
use Quantum\Auth\Authenticators\SessionAuthenticator;
use Quantum\Auth\Context\AuthenticationContextAccessor;
use Quantum\Auth\Contracts\AdaptiveRiskPolicyInterface;
use Quantum\Auth\Contracts\AssuranceContextResolverInterface;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Auth\Contracts\AuthenticationOrchestratorInterface;
use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\AuthenticatorResolverInterface;
use Quantum\Auth\Contracts\DistributedThrottleCounterInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\InventoryReconcilerInterface;
use Quantum\Auth\Contracts\OidcJwksCacheInterface;
use Quantum\Auth\Contracts\OidcSignatureVerifierInterface;
use Quantum\Auth\Contracts\OidcWellKnownClientInterface;
use Quantum\Auth\Contracts\OperationAssurancePolicyInterface;
use Quantum\Auth\Contracts\OpaqueTokenRepositoryInterface;
use Quantum\Auth\Contracts\PasskeyCredentialStoreInterface;
use Quantum\Auth\Contracts\PasskeyCryptoVerifierInterface;
use Quantum\Auth\Contracts\PasswordPolicyInterface;
use Quantum\Auth\Contracts\RefreshTokenRotationStoreInterface;
use Quantum\Auth\Contracts\RiskAdaptivePolicyInterface;
use Quantum\Auth\Contracts\SessionRepositoryDriverFactoryInterface;
use Quantum\Auth\Contracts\ThrottleDistributedStorageInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryDriverFactoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Devices\FileTrustedDeviceRepository;
use Quantum\Auth\Devices\InMemoryTrustedDeviceRepository;
use Quantum\Auth\Devices\InventoryReconciler;
use Quantum\Auth\Federation\Oidc\CurlOidcWellKnownClient;
use Quantum\Auth\Federation\Oidc\FileOidcJwksCache;
use Quantum\Auth\Federation\Oidc\InMemoryMockOidcWellKnownClient;
use Quantum\Auth\Federation\Oidc\InMemoryOidcJwksCache;
use Quantum\Auth\Federation\Oidc\OidcIdentityTokenValidator;
use Quantum\Auth\Federation\Oidc\OpensslJwsSignatureVerifier;
use Quantum\Auth\Identity\LocalIdentityProvider;
use Quantum\Auth\Passkeys\CoseOpensslCryptoVerifier;
use Quantum\Auth\Passkeys\FilePasskeyCredentialStore;
use Quantum\Auth\Passkeys\InMemoryPasskeyCredentialStore;
use Quantum\Auth\Passkeys\PasskeyAuthenticator;
use Quantum\Auth\Passkeys\RelyingPartyConfig;
use Quantum\Auth\Passwords\PasswordPolicy;
use Quantum\Auth\Runtime\AuthenticationOrchestrator;
use Quantum\Auth\Runtime\AuthenticationPolicyEngine;
use Quantum\Auth\Runtime\AuthenticationPolicyRuleInterface;
use Quantum\Auth\Runtime\CompositeAuthenticatorResolver;
use Quantum\Auth\Runtime\DefaultAuthenticatorResolver;
use Quantum\Auth\Runtime\DefaultAssuranceContextResolver;
use Quantum\Auth\Runtime\IdentitySecurityStateRule;
use Quantum\Auth\Runtime\RequestAttributeOperationAssurancePolicy;
use Quantum\Auth\Runtime\SessionCountLimitRule;
use Quantum\Auth\Runtime\TrustedDeviceEnrollmentLimitRule;
use Quantum\Auth\Runtime\SessionRepositoryDriverFactory;
use Quantum\Auth\Runtime\TrustedDeviceRepositoryDriverFactory;
use Quantum\Auth\AbuseProtection\ConfigBasedAdaptiveRiskPolicy;
use Quantum\Auth\Sessions\FileAuthenticationSessionRepository;
use Quantum\Auth\Sessions\InMemoryAuthenticationSessionRepository;
use Quantum\Auth\Tokens\BearerTokenService;
use Quantum\Auth\Tokens\FileOpaqueTokenRepository;
use Quantum\Auth\Tokens\InMemoryOpaqueTokenRepository;
use Quantum\Config\ConfigRepository;
use Quantum\HttpKernel\MiddlewareAliasRegistry;
use Quantum\Middlewares\AuthMiddleware;
use Quantum\Middlewares\BearerAuthMiddleware;
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

        $this->app->singleton(OpaqueTokenRepositoryInterface::class, function (Application $app): OpaqueTokenRepositoryInterface {
            $driver = strtolower(trim((string) $app->config('auth.tokens.driver', 'memory')));

            if ($driver === 'file') {
                try {
                    $storagePath = $app->storagePath('framework/auth/tokens');
                } catch (\Throwable) {
                    $storagePath = null;
                }

                $directory = is_string($storagePath) && trim($storagePath) !== ''
                    ? $storagePath
                    : (sys_get_temp_dir() . '/voltstack-auth-tokens');

                return new FileOpaqueTokenRepository($directory);
            }

            return new InMemoryOpaqueTokenRepository();
        });

        $this->app->scoped(AuthenticatorInterface::class, fn(Application $app) => new PasswordAuthenticator(
            $app->make(IdentityProviderInterface::class),
            $app->make(PasswordPolicyInterface::class),
            $app->make(TrustedDeviceRepositoryInterface::class),
            $app->make(ConfigRepository::class),
        ));

        $this->app->scoped(BearerAuthenticator::class, static function (Application $app): BearerAuthenticator {
            return new BearerAuthenticator(
                $app->make(IdentityProviderInterface::class),
                $app->make(OpaqueTokenRepositoryInterface::class),
            );
        });

        $this->app->scoped(SessionAuthenticator::class);
        $this->app->scoped(AuthenticatorResolverInterface::class, static function (Application $app): AuthenticatorResolverInterface {
            $defaultResolver = new DefaultAuthenticatorResolver(
                $app->make(SessionAuthenticator::class),
                $app->make(AuthenticatorInterface::class),
                $app->make(BearerAuthenticator::class),
            );

            $pipeline = $app->config('auth.authenticators.pipeline', null);

            if (! is_array($pipeline) || count($pipeline) === 0) {
                $base = $defaultResolver;
            } else {
                $composite = new CompositeAuthenticatorResolver();
                $addedDefault = false;

                foreach ($pipeline as $idx => $alias) {
                    $priority = is_int($idx) ? (100 - $idx) : 100;
                    $alias = is_string($alias) && trim($alias) !== '' ? trim($alias) : '';

                    if ($alias === '') {
                        continue;
                    }

                    $resolver = null;
                    try {
                        $candidate = $app->make($alias);
                        if ($candidate instanceof AuthenticatorResolverInterface) {
                            $resolver = $candidate;
                        }
                    } catch (\Throwable) {
                        $resolver = null;
                    }

                    if ($resolver === null) {
                        if ($alias === 'default') {
                            $composite->addResolver($defaultResolver, $priority);
                            $addedDefault = true;
                        }
                        continue;
                    }

                    $composite->addResolver($resolver, $priority);
                }

                if (! $addedDefault) {
                    $composite->addResolver($defaultResolver, 0);
                }

                $base = $composite;
            }

            try {
                $passkeyEnabled = (bool) $app->make(ConfigRepository::class)->get('auth.passkeys.enabled', false);
                if ($passkeyEnabled) {
                    $passkeyAuth = $app->make(PasskeyAuthenticator::class);
                    if ($passkeyAuth instanceof PasskeyAuthenticator) {
                        $passkeyOnlyResolver = new class($passkeyAuth) implements AuthenticatorResolverInterface {
                            public function __construct(private readonly PasskeyAuthenticator $auth) {}
                            /** @return list<PasskeyAuthenticator> */
                            public function resolve(\Quantum\Auth\Runtime\AuthenticationOperationContext $context): array
                            {
                                if ($this->auth->supports($context)) {
                                    return [$this->auth];
                                }
                                return [];
                            }
                        };
                        if ($base instanceof CompositeAuthenticatorResolver) {
                            $base->addResolver($passkeyOnlyResolver, 900);
                        } else {
                            $c = new CompositeAuthenticatorResolver();
                            $c->addResolver($passkeyOnlyResolver, 900);
                            $c->addResolver($base, 0);
                            $base = $c;
                        }
                    }
                }
            } catch (\Throwable) {
            }

            try {
                $oidcEnabled = (bool) $app->make(ConfigRepository::class)->get('auth.oidc.enabled', false);
                if ($oidcEnabled) {
                    $oidcAuth = $app->make(OidcAuthenticator::class);
                    if ($oidcAuth instanceof OidcAuthenticator) {
                        $oidcOnlyResolver = new class($oidcAuth) implements AuthenticatorResolverInterface {
                            public function __construct(private readonly OidcAuthenticator $auth) {}
                            /** @return list<OidcAuthenticator> */
                            public function resolve(\Quantum\Auth\Runtime\AuthenticationOperationContext $context): array
                            {
                                if ($this->auth->supports($context)) {
                                    return [$this->auth];
                                }
                                return [];
                            }
                        };
                        if ($base instanceof CompositeAuthenticatorResolver) {
                            $base->addResolver($oidcOnlyResolver, 850);
                        } else {
                            $c = new CompositeAuthenticatorResolver();
                            $c->addResolver($oidcOnlyResolver, 850);
                            $c->addResolver($base, 0);
                            $base = $c;
                        }
                    }
                }
            } catch (\Throwable) {
            }

            return $base;
        });
        $this->app->scoped(AuthenticationOrchestratorInterface::class, static function (Application $app): AuthenticationOrchestratorInterface {
            $resolver = $app->make(AuthenticatorResolverInterface::class);
            $config = $app->make(ConfigRepository::class);

            $throttle = null;
            if ((bool) $config->get('auth.throttle.enabled', false)) {
                try {
                    $candidate = $app->make(\Quantum\Auth\Contracts\AbuseProtectionThrottleInterface::class);
                    if ($candidate instanceof \Quantum\Auth\Contracts\AbuseProtectionThrottleInterface) {
                        $throttle = $candidate;
                    }
                } catch (\Throwable) {
                    $throttle = null;
                }
            }

            $riskEngine = null;
            if ((bool) $config->get('auth.risk.enabled', false)) {
                try {
                    $signals = $config->get('auth.risk.signals', []);
                    $builtSignals = [];
                    if (is_array($signals)) {
                        foreach ($signals as $spec) {
                            if (is_string($spec) && is_subclass_of($spec, \Quantum\Auth\Contracts\RiskSignalProviderInterface::class)) {
                                try {
                                    $instance = $app->make($spec);
                                    if ($instance instanceof \Quantum\Auth\Contracts\RiskSignalProviderInterface) {
                                        $builtSignals[] = $instance;
                                    }
                                } catch (\Throwable) {
                                }
                            }
                        }
                    }
                    $riskEngine = new \Quantum\Auth\Risk\CompositeRiskSignalEngine($builtSignals);
                } catch (\Throwable) {
                    $riskEngine = null;
                }
            }

            $nonceStore = null;
            if ((bool) $config->get('auth.transaction.nonce.enabled', false)) {
                try {
                    $candidate = $app->make(\Quantum\Auth\Contracts\TransactionNonceStoreInterface::class);
                    if ($candidate instanceof \Quantum\Auth\Contracts\TransactionNonceStoreInterface) {
                        $nonceStore = $candidate;
                    }
                } catch (\Throwable) {
                    try {
                        $ttl = $config->get('auth.transaction.nonce.ttl', 300);
                        $ttlInt = is_numeric($ttl) ? (int) $ttl : 300;
                        $nonceStore = new \Quantum\Auth\Runtime\InMemoryTransactionNonceStore();
                    } catch (\Throwable) {
                        $nonceStore = null;
                    }
                }
            }

            $csrfBinder = null;
            if ($nonceStore !== null || (bool) $config->get('auth.transaction.nonce.enabled', false)) {
                try {
                    $csrfBinder = new \Quantum\Auth\Runtime\CsrfChallengeBinder();
                } catch (\Throwable) {
                    $csrfBinder = null;
                }
            }

            $assuranceContextResolver = null;
            $operationAssurancePolicy = null;
            if ((bool) $config->get('auth.assurance.enabled', false)) {
                try {
                    $candidate = $app->make(AssuranceContextResolverInterface::class);
                    if ($candidate instanceof AssuranceContextResolverInterface) {
                        $assuranceContextResolver = $candidate;
                    }
                } catch (\Throwable) {
                    $assuranceContextResolver = null;
                }

                try {
                    $candidate = $app->make(OperationAssurancePolicyInterface::class);
                    if ($candidate instanceof OperationAssurancePolicyInterface) {
                        $operationAssurancePolicy = $candidate;
                    }
                } catch (\Throwable) {
                    $operationAssurancePolicy = null;
                }
            }

            return new \Quantum\Auth\Runtime\AuthenticationOrchestrator(
                resolver: $resolver,
                throttle: $throttle,
                riskEngine: $riskEngine,
                nonceStore: $nonceStore,
                csrfBinder: $csrfBinder,
                assuranceContextResolver: $assuranceContextResolver,
                operationAssurancePolicy: $operationAssurancePolicy,
            );
        });

        $this->app->scoped(\Quantum\Auth\Contracts\AbuseProtectionThrottleInterface::class, static function (Application $app): ?\Quantum\Auth\Contracts\AbuseProtectionThrottleInterface {
            $config = $app->make(ConfigRepository::class);
            if (! (bool) $config->get('auth.throttle.enabled', false)) {
                return null;
            }
            try {
                $thresholds = $config->get('auth.throttle.thresholds', null);
                $thresholdsArr = is_array($thresholds) && isset($thresholds['1m'], $thresholds['5m'], $thresholds['15m'])
                    ? ['1m' => (int) $thresholds['1m'], '5m' => (int) $thresholds['5m'], '15m' => (int) $thresholds['15m']]
                    : ['1m' => 5, '5m' => 10, '15m' => 20];
                $retryAfter = $config->get('auth.throttle.retry_after_seconds', 60);
                $retryInt = is_numeric($retryAfter) ? (int) $retryAfter : 60;

                return new \Quantum\Auth\AbuseProtection\ThrottleEngineV1(
                    bruteForceCounter: new \Quantum\Auth\AbuseProtection\BruteForceCounter(),
                    bloomFilter: new \Quantum\Auth\AbuseProtection\CredentialStuffingBloomFilter(),
                    thresholds: $thresholdsArr,
                    retryAfterSeconds: $retryInt,
                );
            } catch (\Throwable) {
                return null;
            }
        });

        $this->app->scoped(\Quantum\Auth\Contracts\TransactionNonceStoreInterface::class, static function (Application $app): ?\Quantum\Auth\Contracts\TransactionNonceStoreInterface {
            $config = $app->make(ConfigRepository::class);
            if (! (bool) $config->get('auth.transaction.nonce.enabled', false)) {
                return null;
            }
            try {
                return new \Quantum\Auth\Runtime\InMemoryTransactionNonceStore();
            } catch (\Throwable) {
                return null;
            }
        });

        $this->app->scoped(PasskeyCryptoVerifierInterface::class, static function (Application $app): ?PasskeyCryptoVerifierInterface {
            $config = $app->make(ConfigRepository::class);
            if (! (bool) $config->get('auth.passkeys.crypto.enabled', false)) {
                return null;
            }
            try {
                return new CoseOpensslCryptoVerifier();
            } catch (\Throwable) {
                return null;
            }
        });

        $this->app->scoped(OidcSignatureVerifierInterface::class, static function (Application $app): ?OidcSignatureVerifierInterface {
            $config = $app->make(ConfigRepository::class);
            if (! (bool) $config->get('auth.oidc.signature.enabled', false)) {
                return null;
            }
            try {
                return new OpensslJwsSignatureVerifier();
            } catch (\Throwable) {
                return null;
            }
        });

        $this->app->bind(
            AuthenticationPolicyEngine::class,
            static function (Application $app): ?AuthenticationPolicyEngine {
                $config = $app->make(ConfigRepository::class);
                if (! (bool) $config->get('auth.policy.enabled', false)) {
                    return new AuthenticationPolicyEngine();
                }

                $configured = $config->get('auth.policy.rules', null);

                $rules = is_array($configured) ? $configured : [
                    SessionCountLimitRule::class,
                    TrustedDeviceEnrollmentLimitRule::class,
                    IdentitySecurityStateRule::class,
                ];

                $built = [];
                foreach ($rules as $ruleSpec) {
                    if ($ruleSpec instanceof AuthenticationPolicyRuleInterface) {
                        $built[] = $ruleSpec;
                        continue;
                    }

                    if (is_string($ruleSpec) && is_subclass_of($ruleSpec, AuthenticationPolicyRuleInterface::class)) {
                        if ($ruleSpec === SessionCountLimitRule::class) {
                            $maxConcurrent = $config->get('auth.policy.max_concurrent_sessions_per_identity', null);
                            $built[] = new SessionCountLimitRule(is_int($maxConcurrent) ? $maxConcurrent : null);

                            continue;
                        }

                        if ($ruleSpec === TrustedDeviceEnrollmentLimitRule::class) {
                            $maxDevices = $config->get('auth.policy.max_trusted_devices_per_identity', null);
                            $built[] = new TrustedDeviceEnrollmentLimitRule(
                                is_int($maxDevices) ? $maxDevices : null,
                                $app->make(TrustedDeviceRepositoryInterface::class),
                            );

                            continue;
                        }

                        $built[] = new $ruleSpec();
                    }
                }

                return new AuthenticationPolicyEngine($built);
            },
        );
        $this->app->scoped(AuthManager::class, fn(Application $app) => new AuthManager(
            $app->make(AuthenticationContextAccessor::class),
            $app->make(AuthenticationOrchestratorInterface::class),
            $app->make(AuthenticationSessionRepositoryInterface::class),
            $app->make(TrustedDeviceRepositoryInterface::class),
            $app->make(ConfigRepository::class),
            (bool) $app->make(ConfigRepository::class)->get('auth.policy.enabled', false)
                ? $app->make(AuthenticationPolicyEngine::class)
                : null,
        ));
        $this->app->scoped(AuthenticationManagerInterface::class, fn(Application $app) => $app->make(AuthManager::class));

        $this->app->scoped(RelyingPartyConfig::class, static function (Application $app): RelyingPartyConfig {
            $config = $app->make(ConfigRepository::class);
            $rpId = (string) $config->get('auth.passkeys.rp.id', 'localhost');
            $rpName = (string) $config->get('auth.passkeys.rp.name', 'VoltStack App');
            $origins = $config->get('auth.passkeys.rp.origins', null);
            $originsList = is_array($origins) ? array_values(array_filter($origins, 'is_string')) : [];
            if (count($originsList) === 0) {
                $fallbackAppUrl = (string) $config->get('app.url', '');
                if ($fallbackAppUrl !== '') {
                    $originsList[] = rtrim($fallbackAppUrl, '/');
                } else {
                    $originsList[] = 'http://localhost:8000';
                }
            }
            return new RelyingPartyConfig(
                id: $rpId !== '' ? $rpId : 'localhost',
                name: $rpName !== '' ? $rpName : 'VoltStack App',
                allowedOrigins: $originsList,
            );
        });

        $this->app->scoped(PasskeyCredentialStoreInterface::class, static function (Application $app): ?PasskeyCredentialStoreInterface {
            $config = $app->make(ConfigRepository::class);
            if (! (bool) $config->get('auth.passkeys.enabled', false)) {
                return null;
            }
            $driver = strtolower(trim((string) $config->get('auth.passkeys.store.driver', 'memory')));
            try {
                if ($driver === 'file') {
                    $storagePath = null;
                    try {
                        $storagePath = $app->storagePath('framework/auth/passkeys');
                    } catch (\Throwable) {
                        $storagePath = null;
                    }
                    $directory = is_string($storagePath) && trim($storagePath) !== ''
                        ? $storagePath
                        : (sys_get_temp_dir() . '/voltstack-auth-passkeys');
                    return new FilePasskeyCredentialStore($directory);
                }
                return new InMemoryPasskeyCredentialStore();
            } catch (\Throwable) {
                return null;
            }
        });

        $this->app->scoped(PasskeyAuthenticator::class, static function (Application $app): ?PasskeyAuthenticator {
            $config = $app->make(ConfigRepository::class);
            if (! (bool) $config->get('auth.passkeys.enabled', false)) {
                return null;
            }
            try {
                $rp = $app->make(RelyingPartyConfig::class);
                $store = $app->make(PasskeyCredentialStoreInterface::class);
                if (! $store instanceof PasskeyCredentialStoreInterface) {
                    return null;
                }
                $idp = null;
                try {
                    $candidate = $app->make(IdentityProviderInterface::class);
                    if ($candidate instanceof IdentityProviderInterface) {
                        $idp = $candidate;
                    }
                } catch (\Throwable) {
                    $idp = null;
                }
                return new PasskeyAuthenticator($rp, $store, $idp);
            } catch (\Throwable) {
                return null;
            }
        });

        $this->app->scoped(DistributedThrottleCounterInterface::class, static function (Application $app): ?DistributedThrottleCounterInterface {
            $config = $app->make(ConfigRepository::class);
            if (! (bool) $config->get('auth.throttle.distributed.enabled', false)) {
                return null;
            }
            return null;
        });

        $this->app->scoped(ThrottleDistributedStorageInterface::class, static function (Application $app): ?ThrottleDistributedStorageInterface {
            $config = $app->make(ConfigRepository::class);
            if (! (bool) $config->get('auth.throttle.distributed.enabled', false)) {
                return null;
            }
            try {
                $candidate = $app->make(DistributedThrottleCounterInterface::class);
                return $candidate instanceof ThrottleDistributedStorageInterface ? $candidate : null;
            } catch (\Throwable) {
                return null;
            }
        });

        $this->app->scoped(AdaptiveRiskPolicyInterface::class, static function (Application $app): ?AdaptiveRiskPolicyInterface {
            $config = $app->make(ConfigRepository::class);
            if (! (bool) $config->get('auth.risk.enabled', false) && ! (bool) $config->get('auth.risk.adaptive.enabled', false)) {
                return null;
            }
            try {
                $stepUp = (int) $config->get('auth.risk.adaptive.step_up_threshold', 75);
                $deny = (int) $config->get('auth.risk.adaptive.deny_threshold', 95);
                $strengthName = (string) $config->get('auth.risk.adaptive.required_strength_name', 'multi_factor');
                $strengthValue = (int) $config->get('auth.risk.adaptive.required_strength_value', 500);
                return new ConfigBasedAdaptiveRiskPolicy(
                    stepUpThreshold: max(0, min(100, $stepUp)),
                    denyThreshold: max(0, min(100, $deny)),
                    requiredStrengthName: $strengthName !== '' ? $strengthName : 'multi_factor',
                    requiredStrengthValue: max(0, $strengthValue),
                );
            } catch (\Throwable) {
                return null;
            }
        });

        $this->app->scoped(RiskAdaptivePolicyInterface::class, static function (Application $app): ?RiskAdaptivePolicyInterface {
            $config = $app->make(ConfigRepository::class);
            if (! (bool) $config->get('auth.risk.enabled', false) && ! (bool) $config->get('auth.risk.adaptive.enabled', false)) {
                return null;
            }
            try {
                $candidate = $app->make(AdaptiveRiskPolicyInterface::class);
                return $candidate instanceof RiskAdaptivePolicyInterface ? $candidate : null;
            } catch (\Throwable) {
                return null;
            }
        });

        $this->app->scoped(RefreshTokenRotationStoreInterface::class, static function (Application $app): ?RefreshTokenRotationStoreInterface {
            try {
                $candidate = $app->make(OpaqueTokenRepositoryInterface::class);
                return $candidate instanceof RefreshTokenRotationStoreInterface ? $candidate : null;
            } catch (\Throwable) {
                return null;
            }
        });

        $this->app->scoped(AssuranceContextResolverInterface::class, static function (Application $app): ?AssuranceContextResolverInterface {
            $config = $app->make(ConfigRepository::class);
            if (! (bool) $config->get('auth.assurance.enabled', false)) {
                return null;
            }
            try {
                return new DefaultAssuranceContextResolver();
            } catch (\Throwable) {
                return null;
            }
        });

        $this->app->scoped(OperationAssurancePolicyInterface::class, static function (Application $app): ?OperationAssurancePolicyInterface {
            $config = $app->make(ConfigRepository::class);
            if (! (bool) $config->get('auth.assurance.enabled', false)) {
                return null;
            }
            try {
                return new RequestAttributeOperationAssurancePolicy();
            } catch (\Throwable) {
                return null;
            }
        });

        $this->app->scoped(BearerTokenService::class, static function (Application $app): BearerTokenService {
            $config = $app->make(ConfigRepository::class);
            $accessTtl = (int) $config->get('auth.tokens.access_ttl_seconds', 3600);
            $refreshTtl = (int) $config->get('auth.tokens.refresh_ttl_seconds', 1209600);
            return new BearerTokenService(
                repository: $app->make(OpaqueTokenRepositoryInterface::class),
                accessTokenTtlSec: $accessTtl > 0 ? $accessTtl : 3600,
                refreshTokenTtlSec: $refreshTtl > 0 ? $refreshTtl : 1209600,
            );
        });

        $this->app->scoped(OidcWellKnownClientInterface::class, static function (Application $app): ?OidcWellKnownClientInterface {
            $config = $app->make(ConfigRepository::class);
            if (! (bool) $config->get('auth.oidc.enabled', false)) {
                return null;
            }
            $driver = strtolower(trim((string) $config->get('auth.oidc.well_known.driver', 'curl')));
            try {
                if ($driver === 'mock' || $driver === 'in_memory') {
                    return new InMemoryMockOidcWellKnownClient();
                }
                return new CurlOidcWellKnownClient();
            } catch (\Throwable) {
                return null;
            }
        });

        $this->app->scoped(OidcJwksCacheInterface::class, static function (Application $app): ?OidcJwksCacheInterface {
            $config = $app->make(ConfigRepository::class);
            if (! (bool) $config->get('auth.oidc.enabled', false)) {
                return null;
            }
            $driver = strtolower(trim((string) $config->get('auth.oidc.jwks.driver', 'memory')));
            try {
                if ($driver === 'file') {
                    $storagePath = null;
                    try {
                        $storagePath = $app->storagePath('framework/auth/oidc-jwks');
                    } catch (\Throwable) {
                        $storagePath = null;
                    }
                    $directory = is_string($storagePath) && trim($storagePath) !== ''
                        ? $storagePath
                        : (sys_get_temp_dir() . '/voltstack-auth-oidc-jwks');
                    $ttl = (int) $config->get('auth.oidc.jwks.ttl_seconds', 3600);
                    return new FileOidcJwksCache($directory, $ttl > 0 ? $ttl : 3600);
                }
                return new InMemoryOidcJwksCache((int) $config->get('auth.oidc.jwks.ttl_seconds', 3600));
            } catch (\Throwable) {
                return null;
            }
        });

        $this->app->scoped(OidcAuthenticator::class, static function (Application $app): ?OidcAuthenticator {
            $config = $app->make(ConfigRepository::class);
            if (! (bool) $config->get('auth.oidc.enabled', false)) {
                return null;
            }
            try {
                $sigVerifier = $app->make(OidcSignatureVerifierInterface::class);
                if (! $sigVerifier instanceof OidcSignatureVerifierInterface) {
                    if ((bool) $config->get('auth.oidc.signature.enabled', true)) {
                        $sigVerifier = new OpensslJwsSignatureVerifier();
                    }
                }
                $tokenValidator = new OidcIdentityTokenValidator(
                    $sigVerifier instanceof OidcSignatureVerifierInterface ? $sigVerifier : null,
                );
                $idp = null;
                try {
                    $candidate = $app->make(IdentityProviderInterface::class);
                    if ($candidate instanceof IdentityProviderInterface) {
                        $idp = $candidate;
                    }
                } catch (\Throwable) {
                    $idp = null;
                }
                if (! $idp instanceof IdentityProviderInterface) {
                    return null;
                }
                $jwksCache = null;
                try {
                    $candidate = $app->make(OidcJwksCacheInterface::class);
                    if ($candidate instanceof OidcJwksCacheInterface) {
                        $jwksCache = $candidate;
                    }
                } catch (\Throwable) {
                    $jwksCache = null;
                }
                $expected = $config->get('auth.oidc.expected_claims', []);
                $claims = is_array($expected) ? $expected : [];
                return new OidcAuthenticator($tokenValidator, $idp, $jwksCache, $claims);
            } catch (\Throwable) {
                return null;
            }
        });

        $this->app->scoped(AuthMiddleware::class);
        $this->app->scoped(BearerAuthMiddleware::class);
        $this->app->scoped(GuestMiddleware::class);
        $this->app->scoped(MfaMiddleware::class);

        // The alias must exist before route registration so fluent and attribute routes can resolve it.
        $this->app->make(MiddlewareAliasRegistry::class)->alias('auth', AuthMiddleware::class);
        $this->app->make(MiddlewareAliasRegistry::class)->alias('auth.bearer', BearerAuthMiddleware::class);
        $this->app->make(MiddlewareAliasRegistry::class)->alias('guest', GuestMiddleware::class);
        $this->app->make(MiddlewareAliasRegistry::class)->alias('mfa', MfaMiddleware::class);
    }
}
