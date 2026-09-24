<?php

declare(strict_types=1);

namespace Quantum\Database\Integration;

use Quantum\Database\Config\DatabaseConfiguration;
use Quantum\Database\Config\FrameworkDatabaseConfigurationProvider;
use Quantum\Database\Contracts\DatabaseConfigurationProviderInterface;
use Quantum\Database\Runtime\DatabaseContext;
use Quantum\Database\Runtime\DatabaseExecutionScope;
use Quantum\Database\Runtime\DatabaseExecutionScopeFactory;
use Quantum\Database\Runtime\DatabaseScopeLifecycleManager;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Framework\ServiceProvider;
use VoltStack\Runtime\Context\RuntimeContext;

final class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatabaseConfigurationProviderInterface::class, FrameworkDatabaseConfigurationProvider::class);
        $this->app->singleton(DatabaseConfiguration::class, function (Application $app): DatabaseConfiguration {
            /** @var DatabaseConfigurationProviderInterface $provider */
            $provider = $app->make(DatabaseConfigurationProviderInterface::class);

            return $provider->configuration();
        });
        $this->app->singleton(DatabaseExecutionScopeFactory::class);
        $this->app->singleton(DatabaseCompositionRoot::class, fn(Application $app): DatabaseCompositionRoot => new DatabaseCompositionRoot(
            $app->make(DatabaseConfiguration::class),
            $app->make(DatabaseExecutionScopeFactory::class),
        ));
        $this->app->singleton(DatabaseScopeLifecycleManager::class);

        $this->app->scoped(DatabaseExecutionScope::class, function (Application $app): DatabaseExecutionScope {
            $runtimeContext = RuntimeContext::current();

            if ($runtimeContext === null) {
                throw new RuntimeException('No active runtime context is available for DatabaseExecutionScope.');
            }

            return $app->make(DatabaseExecutionScopeFactory::class)->create(
                $runtimeContext,
                $app->make(DatabaseConfiguration::class),
            );
        });

        $this->app->scoped(DatabaseContext::class, fn(Application $app): DatabaseContext => new DatabaseContext(
            $app->make(DatabaseExecutionScope::class),
            $app->make(DatabaseConfiguration::class),
        ));

        $this->app->onScopeStart(function (Application $app, RuntimeContext $context): void {
            $app->make(DatabaseScopeLifecycleManager::class)->start($context);
        });

        $this->app->onScopeEnd(function (Application $app, ?RuntimeContext $context): void {
            $app->make(DatabaseScopeLifecycleManager::class)->end($context);
        });
    }
}
