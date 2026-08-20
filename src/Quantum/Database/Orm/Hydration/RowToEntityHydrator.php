<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Hydration;

use Quantum\Database\Dbal\Value\QueryResult;
use Quantum\Database\Orm\Mapping\CustomTypeBridgeRegistry;
use Quantum\Database\Orm\Mapping\PropertyAccessorInterface;
use Quantum\Database\Orm\Mapping\TypeSystem;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Orm\Metadata\CompiledPropertyMetadata;
use Quantum\Database\Orm\UnitOfWork\EntityPersister\IdentifierExtractor;
use Quantum\Database\Orm\UnitOfWork\IdentityMapInterface;

/**
 * Implementación default Object Hydrator.
 *
 * Sigue Algoritmo 5.1 (1..9 pasos).
 *
 * - Hot-path: NO usa reflection runtime; closures precomputados via PropertyAccessor.
 * - MAP-001: Misma class+id+tenant → devuelve MISMA instancia.
 * - MAP-006: PK null fatal HydrationException DB_MAP_0701.
 * - Post-hydration hooks: options->postHydrate closure.
 */
final class RowToEntityHydrator implements HydratorInterface
{
    public function __construct(
        private readonly PropertyAccessorInterface  $accessor,
        private readonly IdentifierExtractor        $idExtractor,
        private readonly CustomTypeBridgeRegistry   $typeBridgeRegistry,
        private readonly ?string                    $globalTenantId = null,
    ) {}

    public function hydrateAll(
        QueryResult $qr,
        CompiledEntityMetadata $meta,
        IdentityMapInterface $identityMap,
        HydrationOptions $opts,
    ): iterable {
        return $this->hydrateAllFromRows($qr->fetchAllAssoc(), $meta, $identityMap, $opts);
    }

    public function hydrateOne(
        QueryResult $qr,
        CompiledEntityMetadata $meta,
        IdentityMapInterface $identityMap,
        HydrationOptions $opts,
    ): ?object {
        $row = $qr->fetchOneAssoc();
        if ($row === null) return null;
        $arr = $this->hydrateAllFromRows([$row], $meta, $identityMap, $opts);
        $first = null;
        foreach ($arr as $item) { $first ??= $item; break; }
        return $first;
    }

    public function hydrateAllFromRows(
        array $rows,
        CompiledEntityMetadata $meta,
        IdentityMapInterface $identityMap,
        HydrationOptions $opts,
    ): iterable {
        $out = [];
        foreach ($rows as $row) {
            $entity = $this->hydrateRow($row, $meta, $identityMap, $opts);
            if ($opts->indexById) {
                $hash = $this->idExtractor->hashIdFromRowColumns($row, $meta, $this->globalTenantId);
                $out[$hash] = $entity;
            } else {
                $out[] = $entity;
            }
        }
        return $out;
    }

    // ============== INTERNAL =============================================

    /**
     * @param array<string,mixed> $row
     */
    private function hydrateRow(
        array $row,
        CompiledEntityMetadata $meta,
        IdentityMapInterface $identityMap,
        HydrationOptions $opts,
    ): object {
        // Soft-delete filter (excluir rows que tienen deleted_at!=null)
        if ($opts->excludeSoftDeleted && $meta->softDelete !== null) {
            $sdCol = $meta->softDelete->columnName;
            if (isset($row[$sdCol]) && $row[$sdCol] !== null) {
                throw new HydrationException(
                    "Hydration returned soft-deleted row but excludeSoftDeleted=true; column={$sdCol}",
                    'DB_MAP_0601',
                );
            }
        }

        // 1. Compute id hash from row
        $idHash = $this->idExtractor->hashIdFromRowColumns($row, $meta, $this->globalTenantId);

        // 2/3. IdentityMap lookup, salvo refreshOverride
        if (!$opts->refreshOverride && $identityMap->has($meta->entityClass, $idHash)) {
            $cached = $identityMap->get($meta->entityClass, $idHash);
            if ($cached !== null) {
                return $cached;
            }
        }

        // 4. Construir instancia SIN constructor
        $entity = $this->accessor->newEntityWithoutConstructor($meta);

        // 5. Por cada propiedad (no asociaciones). Associations → MAP-008 se omiten aquí (se gestionan en DDD-07).
        foreach ($meta->properties as $pm) {
            // PK null check
            if ($pm->isIdentifier && !$pm->isNullable && !array_key_exists($pm->columnName, $row)) {
                throw new HydrationException(
                    "Dato corrupto: PK '{$pm->columnName}' no está presente en row de {$meta->entityClass}",
                    'DB_MAP_0701',
                );
            }
            $rawValue = $row[$pm->columnName] ?? $pm->defaultValue;

            // PK null && !nullable → fatal (MAP-006)
            if ($rawValue === null && $pm->isIdentifier && !$pm->isNullable) {
                throw new HydrationException(
                    "Dato corrupto, PK null. Columna '{$pm->columnName}' en {$meta->entityClass}",
                    'DB_MAP_0701',
                );
            }

            $phpValue = $this->rawToPhpFor($rawValue, $pm, $opts);

            $this->accessor->writeValue($entity, $pm, $phpValue);
        }

        // 7. Post hydration
        if ($opts->postHydrate !== null) {
            ($opts->postHydrate)($entity);
        }
        // Si la entity tiene method #[PostHydrate] por convención → método postHydrate()
        if (method_exists($entity, 'postHydrate')) {
            try {
                $entity->postHydrate();
            } catch (\Throwable) {
            }
        }

        // 8. Registrar en IM
        if ($idHash !== '') {
            $identityMap->set($meta->entityClass, $idHash, $entity);
        }

        return $entity;
    }

    private function rawToPhpFor(mixed $rawValue, CompiledPropertyMetadata $pm, HydrationOptions $opts): mixed
    {
        if ($rawValue === null) return null;

        $value = $rawValue;

        if ($opts->autoCastFromDb) {
            try {
                $value = TypeSystem::castDbToPhp($value, $pm);
            } catch (\ValueError $e) {
                throw new HydrationException(
                    "Error casteando DB→PHP property '{$pm->propertyName}' column '{$pm->columnName}': {$e->getMessage()}",
                    'DB_MAP_0702',
                    previous: $e,
                );
            }
        }

        // Enum
        if ($pm->enumClass !== null && $pm->enumClass !== '' && !$value instanceof \UnitEnum) {
            try {
                $value = TypeSystem::castDbToPhp($value, $pm);
            } catch (\Throwable) {
                // fallback raw
            }
        }

        // Custom bridge
        if ($pm->customTypeClass !== null && $pm->customTypeClass !== '') {
            $bridge = $this->typeBridgeRegistry->findBridge(
                is_object($value) ? $value::class : null,
                $pm->customTypeClass,
            );
            if ($bridge !== null) {
                $value = $bridge->toPhpValue($value, $pm);
            }
        }

        return $value;
    }
}
