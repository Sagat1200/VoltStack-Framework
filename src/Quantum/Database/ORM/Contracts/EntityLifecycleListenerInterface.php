<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Contracts;

/**
 * External class-level lifecycle listener contract.
 *
 * Attach via #[Entity(lifecycleListeners: [MyListener::class])] on entity classes.
 * V1 instantiates listeners via `new $listenerClass()` without constructor arguments.
 * Container-aware listeners with dependency injection will be introduced in a later cycle.
 *
 * Prefer extending {@see AbstractEntityLifecycleListener} when you only want to implement
 * a subset of hooks; it provides empty default bodies for every method.
 *
 * Context array for `preUpdate`/`postUpdate` contains at least these keys:
 *   `changes`        → column name → database value being written
 *   `currentValues`  → field name → current in-memory value after the update
 *   `originalSnapshot` → previous snapshot (preUpdate only)
 */
interface EntityLifecycleListenerInterface
{
    public function prePersist(object $entity, object $entityManager, array $context = []): void;

    public function postPersist(object $entity, object $entityManager, array $context = []): void;

    /**
     * @param array{changes?: array<string, mixed>, currentValues?: array<string, mixed>, originalSnapshot?: array<string, mixed>} $context
     */
    public function preUpdate(object $entity, object $entityManager, array $context = []): void;

    /**
     * @param array{changes?: array<string, mixed>, currentValues?: array<string, mixed>} $context
     */
    public function postUpdate(object $entity, object $entityManager, array $context = []): void;

    public function preRemove(object $entity, object $entityManager, array $context = []): void;

    public function postRemove(object $entity, object $entityManager, array $context = []): void;

    public function postLoad(object $entity, object $entityManager, array $context = []): void;
}
