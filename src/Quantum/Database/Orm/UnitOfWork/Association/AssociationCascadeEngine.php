<?php declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\Association;

/**
 * Placeholder CascadeEngine para UnitOfWork (implementación profunda en DDD-07).
 *
 * V1: no-op. El UoW no explota cascadas, pero la fachada existe para que el
 * contrato de UnitOfWork no sea invalido.
 */
final class AssociationCascadeEngine
{
    /**
     * @return iterable<object> entidades adicionales a persist (cascade PERSIST/ALL).
     */
    public function cascadePersist(object $entity, object $em): iterable
    {
        return [];
    }

    /**
     * @return iterable<object> entidades adicionales a remove (cascade REMOVE + orphanRemoval).
     */
    public function cascadeRemove(object $entity, object $em): iterable
    {
        return [];
    }
}
