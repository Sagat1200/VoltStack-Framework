<?php

declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\Event;

use Quantum\Database\Orm\EntityManager\EntityManagerInterface;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Orm\UnitOfWork\ChangeTracking\ChangeSet;

/**
 * Listener tipado para lifecycle del ORM.
 *
 * Permite reaccionar a los hooks emitidos por UnitOfWork durante flush().
 */
interface LifecycleListenerInterface
{
    public function preFlush(EntityManagerInterface $em): void;

    public function postInsert(object $entity, CompiledEntityMetadata $meta, EntityManagerInterface $em): void;

    public function postUpdate(
        object $entity,
        CompiledEntityMetadata $meta,
        ?ChangeSet $changeSet,
        EntityManagerInterface $em,
    ): void;

    public function postDelete(object $entity, CompiledEntityMetadata $meta, EntityManagerInterface $em): void;

    public function postFlush(EntityManagerInterface $em): void;
}
