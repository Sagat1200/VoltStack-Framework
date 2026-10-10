<?php

declare(strict_types=1);

namespace Quantum\Authorization;

use Quantum\Authorization\Authority\RemoteCacheAuthorityRepository;
use Quantum\Authorization\Consistency\VersionedAuthorizationConsistency;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;
use Quantum\Authorization\Contracts\RelationshipRepositoryInterface;
use Quantum\Authorization\Relationship\RemoteCacheRelationshipRepository;
use Quantum\Cache\CacheManager;
use Quantum\Cache\CacheVersionAuthority;
use Quantum\Cache\Contracts\StoreInterface;
use VoltStack\Framework\Application;

/**
 * Small, non-core bootstrap helper that registers the built-in sample
 * remote-cache drivers for authority, relationships, and consistency into
 * {@see AuthorizationDriverRegistry}.
 *
 * Consumers can call:
 *
 *     AuthorizationRemoteCacheDriverProvider::register($app, [
 *         'store'  => 'redis', // optional, uses default store otherwise
 *         'prefix' => 'acme.authz.remote',
 *     ]);
 *
 * and then set config keys:
 *
 *     authorization.authority.driver = remote-cache
 *     authorization.relationships.driver = remote-cache
 *     authorization.consistency.driver = remote-cache
 */
final class AuthorizationRemoteCacheDriverProvider
{
    public const DRIVER_NAME = 'remote-cache';

    /**
     * @param array{store?:string|null,prefix?:string|null,consistency_prefix?:string|null} $options
     */
    public static function register(Application $app, array $options = []): void
    {
        $storeName = isset($options['store']) && is_string($options['store']) && trim($options['store']) !== ''
            ? trim($options['store'])
            : null;
        $prefix = isset($options['prefix']) && is_string($options['prefix']) && trim($options['prefix']) !== ''
            ? trim($options['prefix'])
            : 'authorization.remote';
        $consistencyPrefix = isset($options['consistency_prefix']) && is_string($options['consistency_prefix']) && trim($options['consistency_prefix']) !== ''
            ? trim($options['consistency_prefix'])
            : $prefix . '.consistency.versions';

        $registry = $app->make(AuthorizationDriverRegistry::class);

        $registry->extendConsistency(self::DRIVER_NAME, static function (Application $application) use ($storeName, $consistencyPrefix): AuthorizationConsistencyInterface {
            $store = self::resolveStore($application, $storeName);

            return new VersionedAuthorizationConsistency(
                new CacheVersionAuthority($store, $consistencyPrefix, null),
                'authorization.consistency',
            );
        });

        $registry->extendAuthority(self::DRIVER_NAME, static function (Application $application) use ($storeName, $prefix): AuthorityRepositoryInterface {
            $store = self::resolveStore($application, $storeName);
            $consistency = self::optionalConsistency($application);

            return new RemoteCacheAuthorityRepository(
                $store,
                $prefix . '.authority',
                $consistency,
            );
        });

        $registry->extendRelationships(self::DRIVER_NAME, static function (Application $application) use ($storeName, $prefix): RelationshipRepositoryInterface {
            $store = self::resolveStore($application, $storeName);
            $consistency = self::optionalConsistency($application);

            return new RemoteCacheRelationshipRepository(
                $store,
                $prefix . '.relationships',
                $consistency,
            );
        });
    }

    private static function resolveStore(Application $app, ?string $storeName): StoreInterface
    {
        /** @var CacheManager $cacheManager */
        $cacheManager = $app->make(CacheManager::class);
        $repository = $cacheManager->store($storeName);
        $reflection = new \ReflectionClass($repository);
        $storeProperty = $reflection->getProperty('store');
        $storeProperty->setAccessible(true);
        $store = $storeProperty->getValue($repository);

        if (! $store instanceof StoreInterface) {
            throw new \RuntimeException('Unable to resolve cache store for AuthorizationRemoteCacheDriverProvider.');
        }

        return $store;
    }

    private static function optionalConsistency(Application $app): ?AuthorizationConsistencyInterface
    {
        try {
            return $app->make(AuthorizationConsistencyInterface::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
