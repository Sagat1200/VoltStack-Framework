<?php declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\Event;

use Quantum\Database\Orm\EntityManager\EntityManagerInterface;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Orm\UnitOfWork\ChangeTracking\ChangeSet;

/**
 * Null dispatcher (por defecto). No invoca listeners.
 */
final class NullLifecycleEventDispatcher implements LifecycleEventDispatcherInterface
{
    public function dispatchPreFlush(EntityManagerInterface $em): void {}

    public function dispatchPostInsert(object $entity, CompiledEntityMetadata $meta, EntityManagerInterface $em): void {}

    public function dispatchPostUpdate(
        object $entity,
        CompiledEntityMetadata $meta,
        ?ChangeSet $cs,
        EntityManagerInterface $em,
    ): void {}

    public function dispatchPostDelete(object $entity, CompiledEntityMetadata $meta, EntityManagerInterface $em): void {}

    public function dispatchPostFlush(EntityManagerInterface $em): void {}
}
