<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Proxy;

use Quantum\Database\ORM\Contracts\EntityManagerInterface;
use Quantum\Database\ORM\EntityKey;
use Quantum\Database\ORM\Metadata\EntityMetadata;

/**
 * Creates a placeholder instance for a lazy managed reference.
 *
 * The placeholder MUST be registered as managed with UnitOfWork status
 * Uninitialized BEFORE being returned to the caller. The instantiator only
 * builds the object; initialization (real row load + snapshot population) is
 * performed via EntityManager::initializeProxy() or refresh().
 *
 * Implementations MUST be stateless and singleton-safe (no mutable process
 * state, no container access required).
 */
interface ProxyInstantiatorInterface
{
    /**
     * Build a placeholder proxy object for the given entity metadata and key.
     *
     * The returned object MUST:
     * - implement LazyLoadingPlaceholderInterface,
     * - allow reading/writing the identifier through EntityMetadata helpers,
     * - not require a DB round-trip or a constructor call.
     *
     * Implementations SHOULD throw RuntimeException when the entity class is
     * declared `final` (anonymous classes cannot extend `final` classes) or
     * when reflection otherwise fails to build the placeholder.
     */
    public function instantiatePlaceholder(
        EntityMetadata $metadata,
        EntityKey $key,
        EntityManagerInterface $manager,
    ): object;
}
