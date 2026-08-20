<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Mapping;

use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Orm\Metadata\CompiledPropertyMetadata;

/**
 * EntityToRowMapper: Entity Object → INSERT/UPDATE row array (bindable por PDO).
 *
 * Invariantes:
 *  - MAP-003: toInsertRow excluye propiedades isGenerated salvo forceGenerated.
 *  - MAP-004: toUpdateRow solo incluye changeSet. Si vacío → [].
 *  - MAP-005: toInsertRow no incluye isInsertable=false.
 *  - toUpdateRow no incluye isUpdatable=false aunque esté en changeSet.
 */
final class EntityToRowMapper
{
    public function __construct(
        private readonly PropertyAccessorInterface   $accessor,
        private readonly CustomTypeBridgeRegistry    $typeBridgeRegistry,
    ) {}

    /**
     * Genera row para INSERT.
     *
     * @return array<string,mixed>
     */
    public function toInsertRow(object $entity, CompiledEntityMetadata $meta, bool $forceGenerated = false): array
    {
        $row = [];
        foreach ($meta->properties as $pm) {
            // Skip association columns (los asociaciones OneToOne/ManyToOne con FK son Column + JoinColumn pero tratados via toIdentifierWhere)
            // Pero aquí: solo tratar properties "column real" → todo $properties es column. Las associations no están en $properties.
            // Generated → saltar (menos forceGenerated).
            if (($pm->isGenerated ?? false) && !$forceGenerated) continue;
            if (!($pm->isInsertable ?? true)) continue;
            $value = $this->accessor->readValue($entity, $pm);
            $row[$pm->columnName] = $this->phpToDbFor($value, $pm);
        }
        return $row;
    }

    /**
     * Genera row para UPDATE según $changeSetPropertyNames.
     *
     * @param list<string> $changeSetPropertyNames
     * @return array<string,mixed>
     */
    public function toUpdateRow(object $entity, CompiledEntityMetadata $meta, array $changeSetPropertyNames): array
    {
        if ($changeSetPropertyNames === []) {
            return [];
        }
        $row = [];
        $set = array_flip($changeSetPropertyNames);
        foreach ($meta->properties as $propName => $pm) {
            if (!isset($set[$propName])) continue;
            if (!($pm->isUpdatable ?? true)) continue;
            // Identifier columns nunca se actualizan vía changeSet genérico (por seguridad).
            if (($pm->isIdentifier ?? false)) continue;
            $value = $this->accessor->readValue($entity, $pm);
            $row[$pm->columnName] = $this->phpToDbFor($value, $pm);
        }
        return $row;
    }

    /**
     * Identifier WHERE column→value para UPDATE/DELETE.
     *
     * @return array<string,mixed>
     */
    public function toIdentifierWhere(object $entity, CompiledEntityMetadata $meta): array
    {
        $row = [];
        foreach ($meta->identifierPropertyNames as $propName) {
            $pm = $meta->properties[$propName] ?? throw new \RuntimeException("Missing identifier property {$propName} in {$meta->entityClass}");
            $value = $this->accessor->readValue($entity, $pm);
            $row[$pm->columnName] = $this->phpToDbFor($value, $pm);
        }
        return $row;
    }

    // ========================== INTERNAL =================================

    private function phpToDbFor(mixed $value, CompiledPropertyMetadata $pm): mixed
    {
        // Custom bridge
        $bridge = $this->typeBridgeRegistry->findBridge(
            is_object($value) ? $value::class : null,
            $pm->customTypeClass,
        );
        if ($bridge !== null) {
            return $bridge->toDbValue($value, $pm);
        }
        return TypeSystem::castPhpToDb($value, $pm);
    }
}
