<?php declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\ChangeTracking;

use Quantum\Database\Orm\Mapping\PropertyAccessorInterface;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Operation\Sqg\Enum\DataType;

/**
 * Implementación snapshot del ChangeTracker (Algoritmo 6.2 DDD-V1-06).
 *
 * - takeSnapshot() al persist / hydrate MANAGED.
 * - computeChanges() compara actual vs snapshot; devuelve ?ChangeSet.
 * - epsilon para floats; loose == para DateTime.
 */
final class SnapshotChangeTracker
{
    /**
     * Estructura: key entityClass+idHash (concatenado con \0 separador) → propertyName → dbValue.
     *
     * @var array<string,array<string,mixed>>
     */
    private array $snapshots = [];

    public function __construct(
        private readonly PropertyAccessorInterface $accessor,
    ) {}

    public function takeSnapshot(object $entity, CompiledEntityMetadata $meta, string $idHash): void
    {
        $key = self::key($meta->entityClass, $idHash);
        $snap = [];
        foreach ($meta->properties as $propName => $pm) {
            if (($pm->isAssociation ?? false)) {
                continue;
            }
            $value = $this->accessor->readValue($entity, $pm);
            // Clonar objetos inmutables para evitar "cambio por referencia" (DateTime, etc).
            if (is_object($value)) {
                if ($value instanceof \DateTimeImmutable) {
                    $value = clone $value;
                }
            }
            $snap[$propName] = $value;
        }
        $this->snapshots[$key] = $snap;
    }

    public function computeChanges(object $entity, CompiledEntityMetadata $meta, string $idHash): ?ChangeSet
    {
        $key = self::key($meta->entityClass, $idHash);
        if (!isset($this->snapshots[$key])) {
            // No hay snapshot previo → sin cambios conocidos (o es NEW aún sin PK).
            return null;
        }
        $old = $this->snapshots[$key];
        $changedPropertyNames = [];
        $oldVals = [];
        $newVals = [];
        foreach ($meta->properties as $propName => $pm) {
            if (($pm->isAssociation ?? false)) continue;
            if (($pm->isUpdatable ?? true) === false) continue;

            $prev = $old[$propName] ?? null;
            $cur = $this->accessor->readValue($entity, $pm);

            $equal = self::equal($pm->type->type ?? DataType::Unknown, $prev, $cur);

            if (!$equal) {
                $changedPropertyNames[] = $propName;
                $oldVals[$propName] = $prev;
                $newVals[$propName] = $cur;
            }
        }
        if (count($changedPropertyNames) === 0) {
            return null;
        }
        return new ChangeSet(
            entityClass: $meta->entityClass,
            idHash: $idHash,
            changedPropertyNames: $changedPropertyNames,
            oldValues: $oldVals,
            newValues: $newVals,
        );
    }

    public function removeSnapshot(CompiledEntityMetadata|string $entityOrClass, string $idHash): void
    {
        $class = is_string($entityOrClass) ? $entityOrClass : $entityOrClass->entityClass;
        unset($this->snapshots[self::key($class, $idHash)]);
    }

    public function refreshSnapshot(object $entity, CompiledEntityMetadata $meta, string $idHash): void
    {
        $this->takeSnapshot($entity, $meta, $idHash);
    }

    public function clearAll(): void
    {
        $this->snapshots = [];
    }

    // =============== INTERNAL ==========================================

    private static function key(string $cls, string $idHash): string
    {
        return $cls . "\0" . $idHash;
    }

    private static function equal(DataType $dt, mixed $a, mixed $b): bool
    {
        if ($a === $b) return true;
        if ($a === null || $b === null) return false;
        if (is_object($a) && is_object($b)) {
            if ($a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface) {
                return $a == $b; // loose compare (timezone/format no afecta valor real)
            }
        }
        if ($dt === DataType::Float4 || $dt === DataType::Float8 || $dt === DataType::Numeric) {
            if (is_numeric($a) && is_numeric($b)) {
                $va = (float)$a;
                $vb = (float)$b;
                if ($va === $vb) return true;
                return abs($va - $vb) < 1e-9;
            }
        }
        if (is_object($a) && is_object($b) && $a::class === $b::class) {
            // VOs embedded: igualdad estructural via serialize
            try {
                return serialize($a) === serialize($b);
            } catch (\Throwable) {
                return $a == $b;
            }
        }
        return $a === $b;
    }
}
