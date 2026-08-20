<?php declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\EntityPersister;

use Quantum\Database\Orm\Mapping\PropertyAccessorInterface;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Orm\Metadata\CompiledPropertyMetadata;

/**
 * IdentifierExtractor: extrae identifier (PK) values de una entidad.
 *
 * Soporta: simple (1 columna) y compuesto (N columnas).
 * Métodos: extractColumns() para WHERE; hashId() para IdentityMap key; hasAllIds()
 * para saber si una entidad está insertada o es new.
 */
final class IdentifierExtractor
{
    public function __construct(
        private readonly PropertyAccessorInterface $accessor,
    ) {}

    /**
     * @return array<string,mixed> [columnName => dbValue]
     */
    public function extractColumns(object $entity, CompiledEntityMetadata $meta): array
    {
        $out = [];
        foreach ($meta->identifierPropertyNames as $propName) {
            $pm = $meta->properties[$propName] ?? throw new \RuntimeException(
                "Identifier property '{$propName}' not found in compiled metadata for {$meta->entityClass}",
            );
            $val = $this->accessor->readValue($entity, $pm);
            $out[$pm->columnName] = $val;
        }
        return $out;
    }

    /**
     * Key para IdentityMap lookup.
     *   - PK simple: (string)$id
     *   - PK compuesta: sha1(json_encode([pkCol => val, ...], JSON_THROW_ON_ERROR))
     *   - Si $tenantId !== null → se concatena al final, separando con '#'.
     */
    public function hashId(object $entity, CompiledEntityMetadata $meta, ?string $tenantId = null): string
    {
        $ids = $this->extractColumns($entity, $meta);
        if (count($ids) === 1) {
            [$val] = array_values($ids);
            $base = $val === null ? '' : (is_scalar($val) ? (string)$val : self::dump($val));
        } else {
            ksort($ids);
            $base = self::dump($ids);
        }
        return $tenantId !== null ? "{$tenantId}#{$base}" : $base;
    }

    /**
     * Extrae key hash desde columnas de una row DB (sin la entidad todavía).
     * Útil para hydration step 1.
     *
     * @param array<string,mixed> $row
     */
    public function hashIdFromRowColumns(array $row, CompiledEntityMetadata $meta, ?string $tenantId = null): string
    {
        $ids = [];
        foreach ($meta->identifierPropertyNames as $propName) {
            $pm = $meta->properties[$propName] ?? throw new \RuntimeException("Missing identifier {$propName}");
            $ids[$pm->columnName] = $row[$pm->columnName] ?? null;
        }
        if (count($ids) === 1) {
            [$val] = array_values($ids);
            $base = $val === null ? '' : (is_scalar($val) ? (string)$val : self::dump($val));
        } else {
            ksort($ids);
            $base = self::dump($ids);
        }
        return $tenantId !== null ? "{$tenantId}#{$base}" : $base;
    }

    /**
     * true si TODOS los PK son no-null.
     */
    public function hasAllIds(object $entity, CompiledEntityMetadata $meta): bool
    {
        foreach ($meta->identifierPropertyNames as $propName) {
            $pm = $meta->properties[$propName] ?? throw new \RuntimeException("Missing identifier {$propName}");
            $val = $this->accessor->readValue($entity, $pm);
            if ($val === null) {
                return false;
            }
        }
        return count($meta->identifierPropertyNames) > 0;
    }

    private static function dump(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            return substr(sha1(serialize($value)), 0, 40);
        }
    }
}
