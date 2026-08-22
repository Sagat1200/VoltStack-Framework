<?php declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\Event;

use Quantum\Database\Orm\EntityManager\EntityManagerInterface;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Orm\UnitOfWork\ChangeTracking\ChangeSet;

/**
 * Dispatcher simple basado en listeners de clase registrados por config/container.
 *
 * Mantiene el contrato actual del UnitOfWork sin introducir un bus más grande.
 */
final class ListenerLifecycleEventDispatcher implements LifecycleEventDispatcherInterface
{
    /**
     * @param list<LifecycleListenerInterface> $listeners
     */
    public function __construct(private readonly array $listeners)
    {
    }

    public function dispatchPreFlush(EntityManagerInterface $em): void
    {
        foreach ($this->listeners as $listener) {
            $listener->preFlush($em);
        }
    }

    public function dispatchPostInsert(object $entity, CompiledEntityMetadata $meta, EntityManagerInterface $em): void
    {
        foreach ($this->listeners as $listener) {
            $listener->postInsert($entity, $meta, $em);
        }
    }

    public function dispatchPostUpdate(
        object $entity,
        CompiledEntityMetadata $meta,
        ?ChangeSet $cs,
        EntityManagerInterface $em,
    ): void {
        foreach ($this->listeners as $listener) {
            $listener->postUpdate($entity, $meta, $cs, $em);
        }
    }

    public function dispatchPostDelete(object $entity, CompiledEntityMetadata $meta, EntityManagerInterface $em): void
    {
        foreach ($this->listeners as $listener) {
            $listener->postDelete($entity, $meta, $em);
        }
    }

    public function dispatchPostFlush(EntityManagerInterface $em): void
    {
        foreach ($this->listeners as $listener) {
            $listener->postFlush($em);
        }
    }
}
