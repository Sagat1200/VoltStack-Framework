<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Proxy;

use Quantum\Database\ORM\Contracts\EntityManagerInterface;
use Quantum\Database\ORM\EntityKey;
use Quantum\Database\ORM\Metadata\EntityMetadata;
use ReflectionClass;
use RuntimeException;

/**
 * Default V1 proxy placeholder instantiator.
 *
 * Uses EntityMetadata::newInstance() (which calls
 * ReflectionClass::newInstanceWithoutConstructor()) so no user constructor is
 * triggered and no DB row is read. The identifier is populated directly via
 * EntityMetadata::identifier->setValue() before returning.
 *
 * Because this V1 instantiator does not need to generate anonymous child
 * classes, it also works transparently for entities declared as `final`.
 *
 * LazyLoadingPlaceholderInterface is treated as an OPTIONAL user-land marker
 * in this V1: user classes may implement it explicitly, but the runtime
 * ownership of "Uninitialized" status belongs to UnitOfWork's
 * $proxyInitializationStatus map rather than to the interface alone.
 *
 * This instantiator is stateless and singleton-safe.
 */
final readonly class ReflectionAnonymousProxyInstantiator implements ProxyInstantiatorInterface
{
    public function instantiatePlaceholder(
        EntityMetadata $metadata,
        EntityKey $key,
        EntityManagerInterface $manager,
    ): object {
        $entity = $metadata->newInstance();
        $metadata->identifier->setValue($entity, $key->identifier);

        return $entity;
    }
}
