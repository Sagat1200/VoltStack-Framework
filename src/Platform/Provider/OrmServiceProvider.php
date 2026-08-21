<?php

declare(strict_types=1);

namespace VoltStack\Framework\Provider;

use Quantum\Console\Commands\Database\OrmClearCacheCommand;
use Quantum\Console\Commands\Database\OrmWarmupMetadataCommand;
use Quantum\Database\DatabaseContext;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dialect\DialectInterface;
use Quantum\Database\Orm\EntityManager\EntityManager;
use Quantum\Database\Orm\EntityManager\EntityManagerInterface;
use Quantum\Database\Orm\Hydration\HydratorInterface;
use Quantum\Database\Orm\Hydration\RowToEntityHydrator;
use Quantum\Database\Orm\Mapping\CustomTypeBridgeRegistry;
use Quantum\Database\Orm\Mapping\DefaultPropertyAccessor;
use Quantum\Database\Orm\Mapping\EntityToRowMapper;
use Quantum\Database\Orm\Mapping\PropertyAccessorInterface;
use Quantum\Database\Orm\Metadata\AttributeMetadataLoader;
use Quantum\Database\Orm\Metadata\MetadataManager;
use Quantum\Database\Orm\Metadata\MetadataManagerInterface;
use Quantum\Database\Orm\Support\EntityDiscovery;
use Quantum\Database\Orm\UnitOfWork\ArrayIdentityMap;
use Quantum\Database\Orm\UnitOfWork\Association\AssociationCascadeEngine;
use Quantum\Database\Orm\UnitOfWork\ChangeTracking\SnapshotChangeTracker;
use Quantum\Database\Orm\UnitOfWork\Dependency\DependencyGraph;
use Quantum\Database\Orm\UnitOfWork\EntityPersister\DefaultEntityPersister;
use Quantum\Database\Orm\UnitOfWork\EntityPersister\EntityPersisterInterface;
use Quantum\Database\Orm\UnitOfWork\EntityPersister\IdentifierExtractor;
use Quantum\Database\Orm\UnitOfWork\Event\NullLifecycleEventDispatcher;
use Quantum\Database\Orm\UnitOfWork\IdentityMapInterface;
use Quantum\Database\Orm\UnitOfWork\UnitOfWork;
use VoltStack\Framework\Application;
use VoltStack\Framework\ServiceProvider;

final class OrmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EntityDiscovery::class, function (Application $app): EntityDiscovery {
            $paths = $app->config('database.metadata.entity_paths', [
                'app/Entities',
                'app/Domain/Entities',
            ]);
            $entities = $app->config('database.metadata.entities', []);

            if (!is_array($paths)) {
                $paths = [];
            }

            if (!is_array($entities)) {
                $entities = [];
            }

            return new EntityDiscovery(
                basePath: $app->basePath(),
                entities: $entities,
                paths: $paths,
            );
        });

        $this->app->singleton(PropertyAccessorInterface::class, fn(): PropertyAccessorInterface => new DefaultPropertyAccessor());

        $this->app->singleton(CustomTypeBridgeRegistry::class, function (Application $app): CustomTypeBridgeRegistry {
            $registry = new CustomTypeBridgeRegistry();
            $bridges = $app->config('database.metadata.custom_types', []);

            if (!is_array($bridges)) {
                return $registry;
            }

            foreach ($bridges as $phpClass => $bridgeClass) {
                if (!is_string($phpClass) || !is_string($bridgeClass) || !class_exists($bridgeClass)) {
                    continue;
                }

                $registry->register($phpClass, new $bridgeClass());
            }

            return $registry;
        });

        $this->app->singleton(MetadataManagerInterface::class, function (Application $app): MetadataManagerInterface {
            $cacheDir = $app->config('database.metadata.cache_dir', $app->storagePath('cache/orm/metadata'));
            $devMode = (bool) $app->config('app.debug', true);

            if (is_string($cacheDir) && !preg_match('/^[A-Za-z]:[\\\\\\/]/', $cacheDir) && !str_starts_with($cacheDir, DIRECTORY_SEPARATOR)) {
                $cacheDir = $app->basePath($cacheDir);
            }

            return new MetadataManager(
                loader: new AttributeMetadataLoader(),
                cacheDir: is_string($cacheDir) ? $cacheDir : null,
                devMode: $devMode,
            );
        });

        $this->app->scoped(IdentityMapInterface::class, fn(): IdentityMapInterface => new ArrayIdentityMap());

        $this->app->scoped(SnapshotChangeTracker::class, fn(Application $app): SnapshotChangeTracker => new SnapshotChangeTracker(
            $app->make(PropertyAccessorInterface::class),
        ));

        $this->app->scoped(EntityPersisterInterface::class, fn(Application $app): EntityPersisterInterface => new DefaultEntityPersister(
            new EntityToRowMapper(
                $app->make(PropertyAccessorInterface::class),
                $app->make(CustomTypeBridgeRegistry::class),
            ),
            $app->make(PropertyAccessorInterface::class),
        ));

        $this->app->scoped(HydratorInterface::class, function (Application $app): HydratorInterface {
            $context = $app->make(DatabaseContext::class);

            return new RowToEntityHydrator(
                accessor: $app->make(PropertyAccessorInterface::class),
                idExtractor: new IdentifierExtractor($app->make(PropertyAccessorInterface::class)),
                typeBridgeRegistry: $app->make(CustomTypeBridgeRegistry::class),
                globalTenantId: $context->tenantId,
            );
        });

        $this->app->scoped(UnitOfWork::class, function (Application $app): UnitOfWork {
            return new UnitOfWork(
                identityMap: $app->make(IdentityMapInterface::class),
                changeTracker: $app->make(SnapshotChangeTracker::class),
                persister: $app->make(EntityPersisterInterface::class),
                cascadeEngine: new AssociationCascadeEngine($app->make(MetadataManagerInterface::class)),
                dependencyGraph: new DependencyGraph(),
                eventDispatcher: new NullLifecycleEventDispatcher(),
            );
        });

        $this->app->scoped(EntityManager::class, function (Application $app): EntityManager {
            $context = $app->make(DatabaseContext::class);

            return new EntityManager(
                connection: $app->make(ConnectionInterface::class),
                metadataFactory: $app->make(MetadataManagerInterface::class),
                hydrator: $app->make(HydratorInterface::class),
                unitOfWork: $app->make(UnitOfWork::class),
                dialect: $app->make(DialectInterface::class),
                context: $context,
                tenantId: $context->tenantId,
                accessor: $app->make(PropertyAccessorInterface::class),
                typeBridgeRegistry: $app->make(CustomTypeBridgeRegistry::class),
                identityMap: $app->make(IdentityMapInterface::class),
                changeTracker: $app->make(SnapshotChangeTracker::class),
                persister: $app->make(EntityPersisterInterface::class),
            );
        });

        $this->app->alias(EntityManager::class, EntityManagerInterface::class);
    }

    public function commands(): array
    {
        return [
            OrmWarmupMetadataCommand::class,
            OrmClearCacheCommand::class,
        ];
    }
}
