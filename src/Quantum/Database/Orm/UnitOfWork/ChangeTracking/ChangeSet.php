<?php

declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\ChangeTracking;

/**
 * ChangeSet: set de cambios por entidad.
 *
 * Invariante UOW-008: $changedPropertyNames contiene ÚNICAMENTE properties que realmente cambiaron.
 */
final readonly class ChangeSet
{
    /**
     * @param class-string        $entityClass
     * @param list<string>        $changedPropertyNames   solo columnar (NO associations)
     * @param array<string,mixed> $oldValues              propertyName → snapshot
     * @param array<string,mixed> $newValues              propertyName → actual
     */
    public function __construct(
        public string $entityClass,
        public string $idHash,
        public array  $changedPropertyNames,
        public array  $oldValues,
        public array  $newValues,
    ) {}

    public function hasChanges(): bool
    {
        return count($this->changedPropertyNames) > 0;
    }
}