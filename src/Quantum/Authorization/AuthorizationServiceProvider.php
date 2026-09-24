<?php

declare(strict_types=1);

namespace Quantum\Authorization;

use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Authorization\Ability\AbilityNormalizer;
use Quantum\Authorization\Ability\AbilityRegistry;
use Quantum\Authorization\Contracts\AbilityNormalizerInterface;
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
use Quantum\Authorization\Core\Stages\PolicyAuthorizationStage;
use Quantum\Authorization\Decision\DecisionManager;
use Quantum\Authorization\Gate\GateRegistry;
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
        $this->app->scoped(AuthorizationManager::class);
        $this->app->scoped(
            AuthorizationManagerInterface::class,
            fn(Application $app): AuthorizationManagerInterface => $app->make(AuthorizationManager::class),
        );
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
        ];
    }
}
