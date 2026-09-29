<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Contracts;

/**
 * Convenience base class that implements all methods of
 * {@see EntityLifecycleListenerInterface} with empty bodies.
 *
 * Extend this class instead of implementing the interface directly when you
 * only need to react to a subset of lifecycle events.
 */
abstract class AbstractEntityLifecycleListener implements EntityLifecycleListenerInterface
{
    public function prePersist(object $entity, object $entityManager, array $context = []): void
    {
    }

    public function postPersist(object $entity, object $entityManager, array $context = []): void
    {
    }

    /**
     * @param array{changes?: array<string, mixed>, currentValues?: array<string, mixed>, originalSnapshot?: array<string, mixed>} $context
     */
    public function preUpdate(object $entity, object $entityManager, array $context = []): void
    {
    }

    /**
     * @param array{changes?: array<string, mixed>, currentValues?: array<string, mixed>} $context
     */
    public function postUpdate(object $entity, object $entityManager, array $context = []): void
    {
    }

    public function preRemove(object $entity, object $entityManager, array $context = []): void
    {
    }

    public function postRemove(object $entity, object $entityManager, array $context = []): void
    {
    }

    public function postLoad(object $entity, object $entityManager, array $context = []): void
    {
    }
}
