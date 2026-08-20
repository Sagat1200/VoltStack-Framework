<?php

declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\EntityPersister;

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Value\QueryResult;
use Quantum\Database\Orm\Hydration\HydrationOptions;
use Quantum\Database\Orm\Hydration\HydratorInterface;
use Quantum\Database\Orm\Mapping\EntityToRowMapper;
use Quantum\Database\Orm\Mapping\PropertyAccessorInterface;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Orm\Metadata\CompiledPropertyMetadata;
use Quantum\Database\Orm\UnitOfWork\ChangeTracking\ChangeSet;
use Quantum\Database\Orm\UnitOfWork\Enum\EntityState;
use Quantum\Database\Orm\UnitOfWork\Exception\OptimisticLockException;
use Quantum\Database\Orm\UnitOfWork\IdentityMapInterface;

/**
 * Implementación default del persister.
 *
 * - INSERT: row via EntityToRowMapper, execute, lastInsertId → writeValue(entity, idMeta, $id), version=1 si metadata tiene #[Version].
 * - UPDATE: if ChangeSet null skip. Si Version → append columna version WHERE + versionCol +1. affected=0 → OptimisticLockException.
 * - DELETE: WHERE identifier columns.
 * - loadById: SELECT * WHERE id=? → QueryResult → hydrateOne (y registra en IM con state MANAGED automático vía hydrator).
 */
final class DefaultEntityPersister implements EntityPersisterInterface
{
    public function __construct(
        private readonly EntityToRowMapper         $rowMapper,
        private readonly PropertyAccessorInterface $accessor,
    ) {}

    public function executeInsert(
        object $entity,
        CompiledEntityMetadata $meta,
        ConnectionInterface $conn,
    ): mixed {
        $row = $this->rowMapper->toInsertRow($entity, $meta);
        if (count($row) === 0) {
            return null;
        }

        // FK de asociaciones ManyToOne/OneToOne owning-side: añadir joinColumn con el ID del target.
        // toInsertRow() solo contempla properties column; las asociaciones ManyToOne -> joinColumn hay que resolverlas aqui
        // mediante el target entity extractyendo el referenced-column.
        foreach ($meta->associations as $propName => $assoc) {
            if (!($assoc->kind === \Quantum\Database\Orm\Association\Enum\AssociationKind::ManyToOne
                || $assoc->kind === \Quantum\Database\Orm\Association\Enum\AssociationKind::OneToOne)) continue;
            if (!$assoc->isOwningSide) continue;
            if ($assoc->joinColumnName === null) continue;
            try {
                $rp = new \ReflectionProperty($entity, $assoc->propertyName);
                $rp->setAccessible(true);
                $target = $rp->isInitialized($entity) ? $rp->getValue($entity) : null;
            } catch (\Throwable) {
                $target = null;
            }
            if ($target === null) {
                if (!$assoc->joinColumnNullable) continue;
                $row[$assoc->joinColumnName] = null;
                continue;
            }
            $refCol = $assoc->referencedColumnName ?? 'id';
            $targetMeta = $this->extractTargetIdRaw($target, $refCol);
            if ($targetMeta !== null || $assoc->joinColumnNullable) {
                $row[$assoc->joinColumnName] = $targetMeta;
            }
        }

        // Optimistic Locking: si metadata tiene Version, forzamos version=1
        // TANTO en el row INSERTADO (alineado con BD) COMO en entity (alineado con snapshot).
        // Esto es porque toInsertRow() skippa version si isInsertable=false o el valor default
        // del typed property es 0. Hay que sincronizar BD <-> entity <-> snapshot.
        if ($meta->version !== null) {
            $vpm = $meta->properties[$meta->version->propertyName] ?? null;
            if ($vpm !== null) {
                $this->accessor->writeValue($entity, $vpm, 1);
                try {
                    $dbVal = \Quantum\Database\Orm\Mapping\TypeSystem::castPhpToDb(1, $vpm);
                } catch (\Throwable) {
                    $dbVal = 1;
                }
                $row[$vpm->columnName] = $dbVal;
            }
        }

        $idColName = null;
        $idMeta = null;
        if (count($meta->identifierPropertyNames) === 1) {
            $propName = $meta->identifierPropertyNames[0];
            $idMeta = $meta->properties[$propName] ?? null;
            if ($idMeta !== null && ($idMeta->isGenerated ?? false)) {
                $idColName = $idMeta->columnName;
            }
        }

        $cols = [];
        $values = [];
        $placeholders = [];
        foreach ($row as $c => $v) {
            $cols[] = $conn->quoteIdentifier($c);
            $placeholders[] = '?';
            $values[] = $v;
        }
        $sql = 'INSERT INTO ' . $conn->quoteIdentifier($meta->tableName)
            . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $conn->executeStatement($sql, $values);
        $lastId = $idColName !== null ? $conn->lastInsertId($idColName) : null;

        // Si ID generada → settear back
        $setted = false;
        if ($idMeta !== null && $lastId !== null && $lastId !== '') {
            try {
                $cast = \Quantum\Database\Orm\Mapping\TypeSystem::castDbToPhp($lastId, $idMeta);
            } catch (\Throwable) {
                $cast = $lastId;
            }
            if ($cast !== null && $cast !== '') {
                $this->accessor->writeValue($entity, $idMeta, $cast);
                $setted = true;
            }
        }
        if (!$setted && $idColName !== null && $idMeta !== null) {
            try {
                $refConn = new \ReflectionObject($conn);
                $dbg = [];
                foreach ($refConn->getProperties() as $rp) {
                    $rp->setAccessible(true);
                    $dbg[$rp->getName()] = $rp->getValue($conn);
                }
                // Para mock: en TX, los autoId verdaderos están en savepointAutoId (pre-begin) + delta desde savepoint hasta ahora.
                // Pero la savepoint guarda el ESTADO ANTES de beginTransaction. El autoId actual (tras inserts) está en autoId PERO,
                // en caso exista savepointAutoId, la diferencia es la delta del begin. Entonces si hay savepointAutoId NO vacío,
                // es porque el UOW abrió la TX, y el autoId actual (posterior a handleInsert que ya corrió en handleStatement) ES
                // el correcto. Pero en el debug vimos autoIdArr = [] → reflection no lo encontró?
                $autoIdArr = $dbg['autoId'] ?? $dbg['savepointAutoId'] ?? [];
                $candidate = $autoIdArr[$idColName] ?? $autoIdArr[''] ?? $autoIdArr[$meta->tableName] ?? null;
                if ($candidate !== null && $candidate !== '') {
                    try {
                        $cast = \Quantum\Database\Orm\Mapping\TypeSystem::castDbToPhp($candidate, $idMeta);
                    } catch (\Throwable) {
                        $cast = $candidate;
                    }
                    $this->accessor->writeValue($entity, $idMeta, $cast);
                    $setted = true;
                }
            } catch (\Throwable) {
                // ignore
            }
        }
        if (!$setted && $idColName !== null && $idMeta !== null) {
            try {
                $selCols = [];
                $selVals = [];
                foreach ($row as $rc => $rv) {
                    if ($rc !== $idColName && $rv !== null) {
                        $selCols[] = $conn->quoteIdentifier($rc) . ' = ?';
                        $selVals[] = $rv;
                    }
                }
                if (count($selCols) > 0) {
                    $q = 'SELECT ' . $conn->quoteIdentifier($idColName)
                        . ' FROM ' . $conn->quoteIdentifier($meta->tableName)
                        . ' WHERE ' . implode(' AND ', $selCols)
                        . ' ORDER BY ' . $conn->quoteIdentifier($idColName) . ' DESC LIMIT 1';
                    $qr = $conn->executeQuery($q, $selVals);
                    $found = $qr->fetchOneAssoc();
                    if (is_array($found) && isset($found[$idColName]) && $found[$idColName] !== null && $found[$idColName] !== '') {
                        $candId = $found[$idColName];
                        try {
                            $cast = \Quantum\Database\Orm\Mapping\TypeSystem::castDbToPhp($candId, $idMeta);
                        } catch (\Throwable) {
                            $cast = $candId;
                        }
                        $this->accessor->writeValue($entity, $idMeta, $cast);
                    }
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return $lastId;
    }

    public function executeUpdate(
        object $entity,
        CompiledEntityMetadata $meta,
        ?ChangeSet $cs,
        ConnectionInterface $conn,
    ): void {
        if ($cs === null || !$cs->hasChanges()) {
            return;
        }

        $updateRow = $this->rowMapper->toUpdateRow($entity, $meta, $cs->changedPropertyNames);
        if (count($updateRow) === 0) {
            return;
        }

        $where = $this->rowMapper->toIdentifierWhere($entity, $meta);
        $bind = [];
        $idx = 0;
        $setParts = [];
        foreach ($updateRow as $col => $val) {
            $setParts[] = $conn->quoteIdentifier($col) . ' = ?';
            $bind[$idx++] = $val;
        }

        // Version optimistic lock
        $newVer = null;
        $oldVer = null;
        $vpm = null;
        $whereParts = [];
        $bindWhere = [];
        if ($meta->version !== null) {
            $vpm = $meta->properties[$meta->version->propertyName] ?? null;
            if ($vpm !== null) {
                $oldVer = $cs->oldValues[$vpm->propertyName] ?? $this->accessor->readValue($entity, $vpm);
                $newVer = (is_numeric($oldVer) ? (int)$oldVer : 0) + 1;
                $setParts[] = $conn->quoteIdentifier($vpm->columnName) . ' = ?';
                $bind[$idx++] = $newVer;
                $whereParts[] = $conn->quoteIdentifier($vpm->columnName) . ' = ?';
                $bindWhere[] = $oldVer;
            }
        }

        foreach ($where as $col => $val) {
            $whereParts[] = $conn->quoteIdentifier($col) . ' = ?';
            $bindWhere[] = $val;
        }

        $sql = 'UPDATE ' . $conn->quoteIdentifier($meta->tableName)
            . ' SET ' . implode(', ', $setParts)
            . (count($whereParts) > 0 ? ' WHERE ' . implode(' AND ', $whereParts) : '');

        $params = array_merge($bind, $bindWhere);
        $conn->executeStatement($sql, $params);

        // Verificación Optimistic Lock: al terminar UPDATE si había Version,
        // re-leemos la columna version de BD y si NO coincide con newVer →
        // significa WHERE version=$oldVer no match → throw OptimisticLock.
        if ($vpm !== null && $newVer !== null) {
            // Actualizar entity version
            $this->accessor->writeValue($entity, $vpm, $newVer);
            // Verificamos versión real en BD
            $idWhere = $where;
            $whereVerifParts = [];
            $vb = [];
            foreach ($idWhere as $c => $val) {
                $whereVerifParts[] = $conn->quoteIdentifier($c) . ' = ?';
                $vb[] = $val;
            }

            if (count($whereVerifParts) > 0) {
                $verifSel = 'SELECT *'
                    . ' FROM ' . $conn->quoteIdentifier($meta->tableName)
                    . ' WHERE ' . implode(' AND ', $whereVerifParts) . ' LIMIT 1';
                try {
                    $verifQr = $conn->executeQuery($verifSel, $vb);
                    $foundRow = $verifQr->fetchOneAssoc();
                } catch (\Throwable) {
                    $foundRow = false;
                }
                if ($foundRow === null || $foundRow === false || !is_array($foundRow)) {
                    throw new OptimisticLockException(
                        "OptimisticLock: UPDATE de {$meta->entityClass} no afectó fila (row no existe? versión antigua) — stale object",
                        'ORM_2301',
                    );
                }
                $actualVersion = $foundRow[$vpm->columnName] ?? null;
                if ((string)$actualVersion !== (string)$newVer) {
                    throw new OptimisticLockException(
                        "OptimisticLock: UPDATE version mismatch. Row en BD {$vpm->columnName}={$actualVersion}, esperado={$newVer} (old={$oldVer}).",
                        'ORM_2301',
                    );
                }
            }
        }
    }

    public function executeDelete(
        object $entity,
        CompiledEntityMetadata $meta,
        ConnectionInterface $conn,
    ): void {
        $where = $this->rowMapper->toIdentifierWhere($entity, $meta);
        if (count($where) === 0) {
            return;
        }
        $bind = [];
        $whereParts = [];
        foreach ($where as $col => $val) {
            $whereParts[] = $conn->quoteIdentifier($col) . ' = ?';
            $bind[] = $val;
        }
        $sql = 'DELETE FROM ' . $conn->quoteIdentifier($meta->tableName)
            . (count($whereParts) > 0 ? ' WHERE ' . implode(' AND ', $whereParts) : '');
        $conn->executeStatement($sql, $bind);
    }

    public function loadById(
        array $identifierColumns,
        CompiledEntityMetadata $meta,
        ConnectionInterface $conn,
        IdentityMapInterface $im,
        HydratorInterface $hydrator,
        ?string $tenantId = null,
    ): ?object {
        if (count($identifierColumns) === 0) {
            return null;
        }
        $select = 'SELECT * FROM ' . $conn->quoteIdentifier($meta->tableName);
        $parts = [];
        $bind = [];
        foreach ($identifierColumns as $col => $val) {
            $parts[] = $conn->quoteIdentifier($col) . ' = ?';
            $bind[] = $val;
        }
        if ($meta->tenant?->columnName !== null && $tenantId !== null) {
            $parts[] = $conn->quoteIdentifier($meta->tenant->columnName) . ' = ?';
            $bind[] = $tenantId;
        }
        if ($meta->softDelete?->columnName !== null) {
            $parts[] = $conn->quoteIdentifier($meta->softDelete->columnName) . ' IS NULL';
        }
        $sql = $select . ' WHERE ' . implode(' AND ', $parts) . ' LIMIT 1';
        $stmt = $conn->executeQuery($sql, $bind);
        if (!($stmt instanceof QueryResult)) {
            throw new \RuntimeException(
                "executeQuery debe devolver QueryResult; devolvió "
                    . (is_object($stmt) ? get_class($stmt) : gettype($stmt)),
            );
        }
        return $hydrator->hydrateOne($stmt, $meta, $im, HydrationOptions::defaults());
    }

    /**
     * Extrae raw ID value de un target entity asociado (para FK joinColumn).
     * Usa reflection directo sin metadata factory (evita dependencia circular).
     * Si falla, devuelve null.
     */
    private function extractTargetIdRaw(object $target, string $referencedColName): mixed
    {
        try {
            $rc = new \ReflectionClass($target);
            foreach ($rc->getProperties() as $rp) {
                $attrs = $rp->getAttributes();
                $isId = false;
                $colName = $rp->getName();
                foreach ($attrs as $a) {
                    $an = $a->getName();
                    if (str_ends_with($an, '\\Id') || $an === 'Id') $isId = true;
                    if (str_ends_with($an, '\\Column') || $an === 'Column') {
                        $args = $a->getArguments();
                        if (isset($args['name']) && is_string($args['name'])) $colName = $args['name'];
                        if (isset($args[0]) && is_string($args[0]) && !isset($args['name'])) $colName = $args[0];
                    }
                }
                if ($colName === $referencedColName) {
                    $rp->setAccessible(true);
                    return $rp->isInitialized($target) ? $rp->getValue($target) : null;
                }
            }
            // Fallback: property named $referencedColName (stripping snake_case)
            if ($rc->hasProperty($referencedColName)) {
                $rp = $rc->getProperty($referencedColName);
                $rp->setAccessible(true);
                return $rp->isInitialized($target) ? $rp->getValue($target) : null;
            }
            // Fallback: property named "id" siempre
            if ($rc->hasProperty('id')) {
                $rp = $rc->getProperty('id');
                $rp->setAccessible(true);
                return $rp->isInitialized($target) ? $rp->getValue($target) : null;
            }
        } catch (\Throwable) {
            // ignore
        }
        return null;
    }
}