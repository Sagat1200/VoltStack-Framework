<?php

declare(strict_types=1);

namespace Quantum\Authorization;

use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Authorization\Ability\AbilityNormalizer;
use Quantum\Authorization\Ability\AbilityRegistry;
use Quantum\Authorization\Authority\CachedAuthorityRepository;
use Quantum\Authorization\Authority\InMemoryAuthorityRepository;
use Quantum\Authorization\Authority\RequestScopedAuthorityMemoizationCache;
use Quantum\Authorization\Bridges\ControllerSecurityPlannerBridge;
use Quantum\Authorization\Console\Commands\AuthorizationManifestClearCommand;
use Quantum\Authorization\Console\Commands\AuthorizationManifestCompileCommand;
use Quantum\Authorization\Contracts\AbilityNormalizerInterface;
use Quantum\Authorization\Contracts\AuthorityMemoizationCacheInterface;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;
use Quantum\Authorization\Contracts\AuthorizationContextFactoryInterface;
use Quantum\Authorization\Contracts\AuthorizationManagerInterface;
use Quantum\Authorization\Contracts\AuthorizationMetadataResolverInterface;
use Quantum\Authorization\Contracts\AuthorizationPlannerInterface;
use Quantum\Authorization\Contracts\AuthorizationRequestEnricherInterface;
use Quantum\Authorization\Contracts\PrincipalResolverInterface;
use Quantum\Authorization\Contracts\SubjectResolverInterface;
use Quantum\Authorization\Context\AuthorizationContextFactory;
use Quantum\Authorization\Core\AuthorizationManager;
use Quantum\Authorization\Core\AuthorizationPlanner;
use Quantum\Authorization\Core\AuthorizationRequestFactory;
use Quantum\Authorization\Core\Stages\GateAuthorizationStage;
use Quantum\Authorization\Core\Stages\ManifestRequirementsEnforcementStage;
use Quantum\Authorization\Core\Stages\PolicyAuthorizationStage;
use Quantum\Authorization\Decision\DecisionManager;
use Quantum\Authorization\Gate\GateRegistry;
use Quantum\Authorization\Manifest\Contracts\AuthorizationManifestStoreInterface;
use Quantum\Authorization\Manifest\FilesystemAuthorizationManifestStore;
use Quantum\Authorization\Manifest\InMemoryAuthorizationManifestStore;
use Quantum\Authorization\Metadata\AuthorizationMetadataResolver;
use Quantum\Authorization\Metadata\MetadataAuthorizationContextEnricher;
use Quantum\Authorization\Policy\PolicyDispatcher;
use Quantum\Authorization\Policy\PolicyRegistry;
use Quantum\Authorization\Principal\PrincipalResolver;
use Quantum\Authorization\Subject\SubjectResolver;
use Quantum\Config\ConfigRepository;
use Quantum\Metadata\MetadataMergeStrategy;
use Quantum\Metadata\MetadataValueType;
use Quantum\Metadata\Schema\MetadataSchema;
use Quantum\Metadata\Schema\MetadataSchemaRegistry;
use VoltStack\Framework\Application;
use VoltStack\Framework\ServiceProvider;

final class AuthorizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeDefaultConfiguration();
        $this->registerMetadataSchemas();
        $this->registerManifestStore();
        $this->registerAuthorityRepository();
        $this->registerMemoizationBindings();
        $this->registerControllersSecurityBridgeBinding();

        $this->app->singleton(AbilityRegistry::class);
        $this->app->singleton(GateRegistry::class);
        $this->app->singleton(PolicyRegistry::class, function (Application $app): PolicyRegistry {
            $registry = new PolicyRegistry($app);
            $policies = $app->config('authorization.policies', []);

            if (is_array($policies)) {
                $registry->registerFromConfig($policies);
            }

            return $registry;
        });
        $this->app->singleton(PolicyDispatcher::class);
        $this->app->singleton(DecisionManager::class, function (Application $app): DecisionManager {
            $strategy = $app->config('authorization.default_strategy', 'deny');
            $strategy = is_string($strategy) ? strtolower(trim($strategy)) : 'deny';

            return new DecisionManager(
                defaultStrategy: $strategy === 'allow' ? 'allow' : 'deny',
            );
        });

        $this->app->scoped(AbilityNormalizerInterface::class, AbilityNormalizer::class);
        $this->app->scoped(SubjectResolverInterface::class, SubjectResolver::class);
        $this->app->scoped(PrincipalResolverInterface::class, function (Application $app): PrincipalResolverInterface {
            return new PrincipalResolver($app->make(AuthenticationManagerInterface::class));
        });
        $this->app->scoped(
            AuthorizationMetadataResolverInterface::class,
            fn(Application $app): AuthorizationMetadataResolverInterface => new AuthorizationMetadataResolver(
                $app->make(\Quantum\Metadata\Contracts\MetadataEngineInterface::class),
                $this->resolveManifestStore($app),
            ),
        );
        $this->app->scoped(
            AuthorizationRequestEnricherInterface::class,
            fn(Application $app): AuthorizationRequestEnricherInterface => new MetadataAuthorizationContextEnricher(
                $app->make(AuthorizationMetadataResolverInterface::class),
            ),
        );
        $this->app->scoped(AuthorizationContextFactoryInterface::class, function (Application $app): AuthorizationContextFactoryInterface {
            return new AuthorizationContextFactory($app->make(AuthenticationManagerInterface::class));
        });
        $this->app->scoped(AuthorizationRequestFactory::class);
        $this->app->scoped(ManifestRequirementsEnforcementStage::class, function (Application $app): ManifestRequirementsEnforcementStage {
            $failClosed = $app->config('authorization.fail_closed', true);
            $evaluateConcretely = $app->config('authorization.authority.evaluate_requirements_concretely', false);

            return new ManifestRequirementsEnforcementStage(
                is_bool($failClosed) ? $failClosed : (bool) $failClosed,
                $this->resolveAuthorityRepository($app),
                is_bool($evaluateConcretely) ? $evaluateConcretely : (bool) $evaluateConcretely,
            );
        });
        $this->app->scoped(GateAuthorizationStage::class, function (Application $app): GateAuthorizationStage {
            $failClosed = $app->config('authorization.fail_closed', true);

            return new GateAuthorizationStage(
                $app->make(GateRegistry::class),
                is_bool($failClosed) ? $failClosed : (bool) $failClosed,
            );
        });
        $this->app->scoped(PolicyAuthorizationStage::class, function (Application $app): PolicyAuthorizationStage {
            $failClosed = $app->config('authorization.fail_closed', true);

            return new PolicyAuthorizationStage(
                $app->make(PolicyRegistry::class),
                $app->make(PolicyDispatcher::class),
                is_bool($failClosed) ? $failClosed : (bool) $failClosed,
            );
        });
        $this->app->scoped(AuthorizationPlanner::class, function (Application $app): AuthorizationPlanner {
            $failClosed = $app->config('authorization.fail_closed', true);

            return new AuthorizationPlanner(
                [
                    $app->make(AuthorizationRequestEnricherInterface::class),
                ],
                [
                    $app->make(ManifestRequirementsEnforcementStage::class),
                    $app->make(GateAuthorizationStage::class),
                    $app->make(PolicyAuthorizationStage::class),
                ],
                $app->make(DecisionManager::class),
                is_bool($failClosed) ? $failClosed : (bool) $failClosed,
            );
        });
        $this->app->scoped(
            AuthorizationPlannerInterface::class,
            fn(Application $app): AuthorizationPlannerInterface => $app->make(AuthorizationPlanner::class),
        );
        $this->app->scoped(AuthorizationManager::class, function (Application $app): AuthorizationManager {
            return new AuthorizationManager(
                requests: $app->make(AuthorizationRequestFactory::class),
                planner: $app->make(AuthorizationPlannerInterface::class),
                authority: $this->resolveAuthorityRepository($app),
                authorityEarlyGateEnabled: $this->booleanOf($app->config('authorization.authority.early_gate_enabled', false)),
            );
        });
        $this->app->scoped(
            AuthorizationManagerInterface::class,
            fn(Application $app): AuthorizationManagerInterface => $app->make(AuthorizationManager::class),
        );
    }

    private function registerManifestStore(): void
    {
        $this->app->singleton(
            AuthorizationManifestStoreInterface::class,
            function (Application $app): AuthorizationManifestStoreInterface {
                $manifestPath = $app->config('authorization.manifest.path');

                if (is_string($manifestPath) && trim($manifestPath) !== '') {
                    return new FilesystemAuthorizationManifestStore($manifestPath);
                }

                return new InMemoryAuthorizationManifestStore();
            },
        );
    }

    private function resolveManifestStore(Application $app): ?AuthorizationManifestStoreInterface
    {
        $enabled = $app->config('authorization.manifest.enabled', true);

        if (! $this->booleanOf($enabled)) {
            return null;
        }

        try {
            return $app->make(AuthorizationManifestStoreInterface::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function registerAuthorityRepository(): void
    {
        $this->app->singleton(
            AuthorityRepositoryInterface::class,
            function (Application $app): AuthorityRepositoryInterface {
                $config = $app->config('authorization.authority.grants', []);
                $seed = is_array($config) ? $config : [];
                $inner = new InMemoryAuthorityRepository($seed);
                $memoize = $app->config('authorization.authority.memoize', true);
                if (! $this->booleanOf($memoize)) {
                    return $inner;
                }

                try {
                    $cache = $app->make(AuthorityMemoizationCacheInterface::class);

                    return new CachedAuthorityRepository($inner, $cache);
                } catch (\Throwable) {
                    return $inner;
                }
            },
        );
    }

    private function registerMemoizationBindings(): void
    {
        $this->app->scoped(
            AuthorityMemoizationCacheInterface::class,
            static fn (): AuthorityMemoizationCacheInterface => new RequestScopedAuthorityMemoizationCache(),
        );
    }

    private function registerControllersSecurityBridgeBinding(): void
    {
        if (! class_exists(ControllerSecurityPlannerBridge::class)) {
            return;
        }

        $bridgeEnabled = $this->app->config('authorization.controllers_security.bridge.enabled', false);
        if (! $this->booleanOf($bridgeEnabled)) {
            return;
        }

        $this->app->scoped(ControllerSecurityPlannerBridge::class, static function (Application $app): ControllerSecurityPlannerBridge {
            return new ControllerSecurityPlannerBridge(
                authorization: $app->make(AuthorizationManagerInterface::class),
                requestFactory: $app->make(AuthorizationRequestFactory::class),
            );
        });
    }

    private function resolveAuthorityRepository(Application $app): ?AuthorityRepositoryInterface
    {
        $enabled = $app->config('authorization.authority.enabled', true);

        if (! $this->booleanOf($enabled)) {
            return null;
        }

        try {
            return $app->make(AuthorityRepositoryInterface::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function mergeDefaultConfiguration(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $existing = $config->get('authorization', []);
        $existing = is_array($existing) ? $existing : [];
        $config->set('authorization', $this->mergeRecursive($this->defaults(), $existing));
    }

    private function registerMetadataSchemas(): void
    {
        $registry = $this->app->make(MetadataSchemaRegistry::class);

        $registry->register(new MetadataSchema(
            key: 'authorization.public',
            type: MetadataValueType::Bool,
            merge: MetadataMergeStrategy::Replace,
            defaultValue: false,
        ));
        $registry->register(new MetadataSchema(
            key: 'authorization.requirements',
            type: MetadataValueType::Array,
            merge: MetadataMergeStrategy::Append,
            defaultValue: [],
        ));
    }

    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function mergeRecursive(array $defaults, array $overrides): array
    {
        $merged = $defaults;

        foreach ($overrides as $key => $value) {
            if (isset($merged[$key]) && is_array($merged[$key]) && is_array($value)) {
                $merged[$key] = $this->mergeRecursive($merged[$key], $value);
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    private function booleanOf(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return (bool) $value;
    }

    /**
     * @return list<class-string<\Quantum\Console\Command>>
     */
    public function commands(): array
    {
        return [
            AuthorizationManifestCompileCommand::class,
            AuthorizationManifestClearCommand::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return [
            'default_strategy' => 'deny',
            'fail_closed' => true,
            'abilities' => [],
            'policies' => [],
            'manifest' => [
                'enabled' => true,
                'path' => null,
            ],
            'authority' => [
                'enabled' => true,
                'evaluate_requirements_concretely' => false,
                'grants' => [],
                'memoize' => true,
                'early_gate_enabled' => false,
            ],
            'controllers_security' => [
                'bridge' => [
                    'enabled' => false,
                ],
            ],
        ];
    }
}
