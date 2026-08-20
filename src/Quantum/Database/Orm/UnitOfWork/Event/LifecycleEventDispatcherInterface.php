<?php declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\Event;

use Quantum\Database\Orm\EntityManager\EntityManagerInterface;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Orm\UnitOfWork\ChangeTracking\ChangeSet;

/**
 * Eventos del lifecycle de entity (interfaces mínimas, implementable por Doctrine listeners).
 *
 * V1: solo dispatch PreFlush y Post* (PostInsert, PostUpdate, PostDelete, PostFlush).
 * La implementación completa (listeners/suscribers) está en DDD-08/Dispatcher.
 */
interface LifecycleEventDispatcherInterface
{
    public function dispatchPreFlush(EntityManagerInterface $em): void;

    public function dispatchPostInsert(object $entity, CompiledEntityMetadata $meta, EntityManagerInterface $em): void;

    public function dispatchPostUpdate(
        object $entity,
        CompiledEntityMetadata $meta,
        ?ChangeSet $cs,
        EntityManagerInterface $em,
    ): void;

    public function dispatchPostDelete(object $entity, CompiledEntityMetadata $meta, EntityManagerInterface $em): void;

    public function dispatchPostFlush(EntityManagerInterface $em): void;
}
