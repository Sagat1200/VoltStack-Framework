<?php declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\Association;

use Quantum\Database\Orm\Association\Collection\PersistentCollection;
use Quantum\Database\Orm\EntityManager\EntityManagerInterface;
use Quantum\Database\Orm\Hydration\HydrationOptions;
use Quantum\Database\Orm\Metadata\CompiledAssociationMetadata;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;

/**
 * AssociationHydrationLoader: usados por Hydrator para:
 *   - toOne associations: createProxy → lazy proxy (V1 fallback EM::find eager, V2 gen proxy class)
 *   - toMany associations: createLazyCollection → PersistentCollection uninited + loader closure SQL.
 */
final class AssociationHydrationLoader
{
    /**
     * Crea PersistentCollection lazy para OneToMany o ManyToMany.
     */
    public function createLazyCollection(
        CompiledAssociationMetadata $assoc,
        object $ownerEntity,
        CompiledEntityMetadata $ownerMeta,
        EntityManagerInterface $em,
    ): PersistentCollection {
        $targetClass = $assoc->targetEntityClass;
        $order = $assoc->defaultOrderBy;
        $limit = $assoc->defaultLimit;
        $indexBy = $assoc->indexBy;
        $joinColumnReferenced = $assoc->referencedColumnName ?? 'id';

        if ($assoc->kind === \Quantum\Database\Orm\Association\Enum\AssociationKind::OneToMany) {
            $mappedBy = $assoc->mappedBy ?? '';
            try {
                $owningMeta = $em->getMetadataFactory()->getMetadataFor($targetClass);
                $owningAssoc = $owningMeta->associations[$mappedBy] ?? null;
                $joinColName = $owningAssoc?->joinColumnName ?? ($mappedBy . '_id');
            } catch (\Throwable) {
                $joinColName = $mappedBy . '_id';
            }
            $joinColTargetValue = self::readColRaw($ownerEntity, $joinColumnReferenced);

            $loader = function () use (
                $em,
                $targetClass,
                $joinColName,
                $joinColTargetValue,
                $order,
                $limit,
                $indexBy,
            ): array {
                if ($joinColName === '' || $joinColTargetValue === null) return [];
                $conn = $em->getConnection();
                $dialect = $em->getDialect();
                try {
                    $targetMeta = $em->getMetadataFactory()->getMetadataFor($targetClass);
                    $table = $targetMeta->tableName;
                } catch (\Throwable) {
                    $table = self::defaultTableName($targetClass);
                }
                $qTbl = $dialect->quoteIdentifier($table);
                $qCol = $dialect->quoteIdentifier($joinColName);
                $orderBySql = self::orderBySql($order, $dialect);
                $limitSql = $limit !== null ? " LIMIT {$limit}" : '';
                $sql = "SELECT * FROM {$qTbl} WHERE {$qCol} = ?{$orderBySql}{$limitSql}";
                $stmt = $conn->executeQuery($sql, [$joinColTargetValue]);
                $hydrator = $em->getHydrator();
                try {
                    $targetMeta2 = $em->getMetadataFactory()->getMetadataFor($targetClass);
                } catch (\Throwable) {
                    return [];
                }
                $im = $em->getIdentityMap();
                $results = [];
                foreach ($hydrator->hydrateAll($stmt, $targetMeta2, $im, HydrationOptions::defaults()) as $row) {
                    if (!is_object($row)) continue;
                    if ($indexBy !== null) {
                        $k = self::readColRaw($row, $indexBy);
                        if ($k === null) $results[] = $row;
                        else $results[$k] = $row;
                    } else {
                        $results[] = $row;
                    }
                }
                return array_values($results);
            };

            return new PersistentCollection($assoc, $em, $loader);
        }

        if ($assoc->kind === \Quantum\Database\Orm\Association\Enum\AssociationKind::ManyToMany) {
            $joinTable = $assoc->joinTableName;
            $thisSide = $assoc->joinColumnThisSide ?? strtolower($this->shortName($assoc)) . '_id';
            $tgtSide = $assoc->joinColumnTargetSide ?? strtolower($this->shortClassName($targetClass)) . '_id';
            $ownerId = self::readColRaw($ownerEntity, $joinColumnReferenced);

            $loader = function () use (
                $em,
                $targetClass,
                $joinTable,
                $thisSide,
                $tgtSide,
                $ownerId,
                $order,
                $limit,
                $indexBy,
            ): array {
                if ($ownerId === null || $joinTable === null) return [];
                $conn = $em->getConnection();
                $dialect = $em->getDialect();
                try {
                    $targetMeta = $em->getMetadataFactory()->getMetadataFor($targetClass);
                    $table = $targetMeta->tableName;
                } catch (\Throwable) {
                    $table = self::defaultTableName($targetClass);
                }
                $qTgt = $dialect->quoteIdentifier($table);
                $qJT = $dialect->quoteIdentifier($joinTable);
                $qThis = $dialect->quoteIdentifier($thisSide);
                $qTgtCol = $dialect->quoteIdentifier($tgtSide);
                $qTgtPK = $dialect->quoteIdentifier('id');
                $orderBySql = self::orderBySql($order, $dialect);
                $limitSql = $limit !== null ? " LIMIT {$limit}" : '';
                $sql = "SELECT t.* FROM {$qTgt} t INNER JOIN {$qJT} jt ON jt.{$qTgtCol} = t.{$qTgtPK}"
                     . " WHERE jt.{$qThis} = ?{$orderBySql}{$limitSql}";
                $stmt = $conn->executeQuery($sql, [$ownerId]);
                $hydrator = $em->getHydrator();
                try {
                    $targetMeta2 = $em->getMetadataFactory()->getMetadataFor($targetClass);
                } catch (\Throwable) {
                    return [];
                }
                $im = $em->getIdentityMap();
                $results = [];
                foreach ($hydrator->hydrateAll($stmt, $targetMeta2, $im, HydrationOptions::defaults()) as $row) {
                    if (!is_object($row)) continue;
                    if ($indexBy !== null) {
                        $k = self::readColRaw($row, $indexBy);
                        if ($k === null) $results[] = $row;
                        else $results[$k] = $row;
                    } else {
                        $results[] = $row;
                    }
                }
                return array_values($results);
            };

            return new PersistentCollection($assoc, $em, $loader);
        }

        return new PersistentCollection($assoc, $em, null);
    }

    /**
     * V1 minimum: no implementamos proxy toOne, usamos find() eager como fallback.
     */
    public function createProxy(
        CompiledAssociationMetadata $assoc,
        mixed $foreignKeyValue,
        EntityManagerInterface $em,
    ): ?object {
        if ($foreignKeyValue === null) return null;
        try {
            return $em->find($assoc->targetEntityClass, $foreignKeyValue);
        } catch (\Throwable) {
            return null;
        }
    }

    // ---------- Helpers ----------
    public static function readColRaw(object $entity, string $colNameOrProperty): mixed
    {
        $rc = new \ReflectionClass($entity);
        // Buscar por nombre de columna attribute Column(name=X)
        foreach ($rc->getProperties() as $rp) {
            $name = $rp->getName();
            foreach ($rp->getAttributes() as $a) {
                $an = $a->getName();
                if (str_ends_with($an, '\\Column') || $an === 'Column') {
                    $args = $a->getArguments();
                    $name = $args['name'] ?? $name;
                    break;
                }
            }
            if ($name === $colNameOrProperty) {
                $rp->setAccessible(true);
                return $rp->isInitialized($entity) ? $rp->getValue($entity) : null;
            }
        }
        if ($rc->hasProperty($colNameOrProperty)) {
            $rp = $rc->getProperty($colNameOrProperty);
            $rp->setAccessible(true);
            return $rp->isInitialized($entity) ? $rp->getValue($entity) : null;
        }
        if ($rc->hasProperty('id')) {
            $rp = $rc->getProperty('id');
            $rp->setAccessible(true);
            return $rp->isInitialized($entity) ? $rp->getValue($entity) : null;
        }
        return null;
    }

    private static function defaultTableName(string $entityClass): string
    {
        $parts = explode('\\', $entityClass);
        $last = end($parts);
        return strtolower($last) . 's';
    }

    /**
     * @param array<string,string> $orderBy ['col'=>'ASC']
     */
    private static function orderBySql(array $orderBy, object $dialect): string
    {
        if (count($orderBy) === 0) return '';
        $parts = [];
        foreach ($orderBy as $prop => $dir) {
            $q = $dialect->quoteIdentifier($prop);
            $d = strtoupper((string)$dir) === 'DESC' ? 'DESC' : 'ASC';
            $parts[] = "{$q} {$d}";
        }
        return ' ORDER BY ' . implode(', ', $parts);
    }

    private function shortName(CompiledAssociationMetadata $assoc): string
    {
        return $assoc->propertyName;
    }

    private function shortClassName(string $cls): string
    {
        $parts = explode('\\', $cls);
        $last = end($parts);
        return lcfirst($last);
    }
}
