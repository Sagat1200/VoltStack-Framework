<?php

declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\Association;

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dialect\DialectInterface;
use Quantum\Database\Orm\Association\Collection\PersistentCollection;
use Quantum\Database\Orm\Association\Enum\AssociationKind;
use Quantum\Database\Orm\Metadata\CompiledAssociationMetadata;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;

/**
 * AssociationPersister:
 *   - ManyToMany: insert/delete pivot rows (tabla joinTable).
 *   - toOne owning: inject Foreign key value into row data array (INSERT/UPDATE SQL value set).
 */
final class AssociationPersister
{
    public function __construct(
        private readonly ConnectionInterface $conn,
        private readonly DialectInterface $dialect,
    ) {}

    /**
     * ManyToMany: inserta multi-INSERT en batches en pivot joinTable.
     *
     * @param CompiledAssociationMetadata $assoc (isOwningSide ManyToMany)
     * @param list<array{0:mixed,1:mixed}> $pairs [ownerId, targetId]
     */
    public function insertManyToManyRows(CompiledAssociationMetadata $assoc, array $pairs): void
    {
        if (count($pairs) === 0 || $assoc->joinTableName === null) return;
        $thisCol = $assoc->joinColumnThisSide ?? $this->defaultThisColumn($assoc);
        $tgtCol = $assoc->joinColumnTargetSide ?? $this->defaultTargetColumn($assoc);
        $quotedTable = $this->dialect->quoteIdentifier($assoc->joinTableName);
        $quotedThisCol = $this->dialect->quoteIdentifier($thisCol);
        $quotedTgtCol = $this->dialect->quoteIdentifier($tgtCol);

        $batchSize = 100;
        foreach (array_chunk($pairs, $batchSize) as $chunk) {
            $placeholders = [];
            $binds = [];
            foreach ($chunk as $pair) {
                $placeholders[] = "(?, ?)";
                $binds[] = $pair[0];
                $binds[] = $pair[1];
            }
            $sql = "INSERT INTO {$quotedTable} ({$quotedThisCol}, {$quotedTgtCol}) VALUES "
                . implode(', ', $placeholders);
            $this->conn->executeStatement($sql, $binds);
        }
    }

    /**
     * Sync pivot V1 safe: (1) DELETE todas las filas pivot para este owner,
     * (2) INSERT todas las filas actuales de la colección.
     * Robusto V1: evita divergencias snapshot vs dirty tracking de
     * ArrayCollection/PersistentCollection. Para owner MANAGED es idempotente
     * (borra y reinserta todas; pivot sin PK unique no hay risk).
     */
    public function updateOwningSideManyToMany(
        CompiledAssociationMetadata $assoc,
        object $ownerEntity,
        CompiledEntityMetadata $ownerMeta,
        PersistentCollection $currentCollection,
    ): void {
        if (!$assoc->isOwningSide || $assoc->joinTableName === null) return;
        $ownerId = self::extractIdRaw($ownerEntity, $ownerMeta);
        if ($ownerId === null) return;

        $thisCol = $assoc->joinColumnThisSide ?? $this->defaultThisColumn($assoc);
        $tgtCol = $assoc->joinColumnTargetSide ?? $this->defaultTargetColumn($assoc);

        $quotedTbl = $this->dialect->quoteIdentifier($assoc->joinTableName);
        $quotedThis = $this->dialect->quoteIdentifier($thisCol);
        $quotedTgt = $this->dialect->quoteIdentifier($tgtCol);

        try {
            $sqlDel = "DELETE FROM {$quotedTbl} WHERE {$quotedThis} = ?";
            $this->conn->executeStatement($sqlDel, [$ownerId]);
        } catch (\Throwable) {
        }

        $targetMeta = $this->resolveTargetMeta($assoc);
        $pairs = [];
        $arr = $currentCollection->toArray();
        foreach ($arr as $tgt) {
            if (!is_object($tgt)) continue;
            $tgtId = self::extractIdRaw($tgt, $targetMeta);
            if ($tgtId === null) continue;
            $pairs[] = [$ownerId, $tgtId];
        }
        if (count($pairs) > 0) {
            $this->insertManyToManyRows($assoc, $pairs);
        }
    }

    /**
     * Inyecta en $rowData el valor FK para asociaciones owning to-one
     * (ManyToOne / OneToOne owning).
     *
     * @param array<string,mixed> $rowData referencia a la fila que se enviará al SQL INSERT/UPDATE.
     */
    public function injectForeignKeyIntoRowData(
        CompiledAssociationMetadata $assoc,
        object $entity,
        array &$rowData,
    ): void {
        if ($assoc->joinColumnName === null) return;
        if (!in_array($assoc->kind, [
            AssociationKind::ManyToOne,
            AssociationKind::OneToOne,
        ], true)) return;
        if (!$assoc->isOwningSide) return;

        try {
            $rp = new \ReflectionProperty($entity, $assoc->propertyName);
            $rp->setAccessible(true);
            if (!$rp->isInitialized($entity)) {
                if ($assoc->joinColumnNullable) $rowData[$assoc->joinColumnName] = null;
                return;
            }
            $target = $rp->getValue($entity);
            if ($target === null) {
                if ($assoc->joinColumnNullable) $rowData[$assoc->joinColumnName] = null;
                return;
            }
            $refCol = $assoc->referencedColumnName ?? 'id';
            $rowData[$assoc->joinColumnName] = self::extractTargetIdByColumnName($target, $refCol);
        } catch (\ReflectionException) {
        }
    }

    private function resolveTargetMeta(CompiledAssociationMetadata $assoc): CompiledEntityMetadata
    {
        // V1 placeholder stub con identifierPropertyNames=[id] (extractIdRaw usa identifierPropertyNames).
        static $stubs = [];
        $k = $assoc->targetEntityClass;
        if (!isset($stubs[$k])) {
            $stubs[$k] = new CompiledEntityMetadata(
                entityClass: $assoc->targetEntityClass,
                tableName: '__placeholder__',
                schemaName: null,
                repositoryClass: \stdClass::class,
                readOnly: false,
                identifierPropertyNames: ['id'],
                properties: [],
                associations: [],
                columnToPropertyMap: [],
                softDelete: null,
                timestamps: null,
                tenant: null,
                version: null,
                inheritance: null,
                fingerprint: '__placeholder__' . $k,
                compiledAt: time(),
            );
        }
        return $stubs[$k];
    }

    private function defaultThisColumn(CompiledAssociationMetadata $assoc): string
    {
        // Usamos el nombre de propiedad que referencia la entidad actual en la relación
        // Como no tenemos acceso al MM aquí, usamos convention default: <ownerClass>_id
        return strtolower($assoc->propertyName) . '_id';
    }

    private function defaultTargetColumn(CompiledAssociationMetadata $assoc): string
    {
        return strtolower($this->shortClassName($assoc->targetEntityClass)) . '_id';
    }

    /**
     * Extrae PK id de una entidad:
     *   1) busca por nombres de propiedades en $meta->identifierPropertyNames.
     *   2) fallback genérico reflection property "id".
     */
    public static function extractIdRaw(object $entity, CompiledEntityMetadata $meta): mixed
    {
        foreach ($meta->identifierPropertyNames as $pkProp) {
            try {
                $rp = new \ReflectionProperty($entity, $pkProp);
                $rp->setAccessible(true);
                if ($rp->isInitialized($entity)) {
                    $v = $rp->getValue($entity);
                    if ($v !== null) return $v;
                }
            } catch (\ReflectionException) {
            }
        }
        try {
            $rc = new \ReflectionClass($entity);
            if ($rc->hasProperty('id')) {
                $rp = $rc->getProperty('id');
                $rp->setAccessible(true);
                if ($rp->isInitialized($entity)) return $rp->getValue($entity);
            }
        } catch (\ReflectionException) {
        }
        return null;
    }

    /**
     * Extrae el valor de la columna $referencedColName en $target.
     * Busca por atributo #[Column(name=X)], luego reflection property igual nombre, fallback id.
     */
    public static function extractTargetIdByColumnName(object $target, string $referencedColName): mixed
    {
        $rc = new \ReflectionClass($target);
        foreach ($rc->getProperties() as $rp) {
            $colName = $rp->getName();
            foreach ($rp->getAttributes() as $a) {
                $an = $a->getName();
                if (str_ends_with($an, '\\Column') || $an === 'Column') {
                    $args = $a->getArguments();
                    $colName = $args['name'] ?? $colName;
                    break;
                }
            }
            if ($colName === $referencedColName) {
                $rp->setAccessible(true);
                return $rp->isInitialized($target) ? $rp->getValue($target) : null;
            }
        }
        if ($rc->hasProperty($referencedColName)) {
            $rp = $rc->getProperty($referencedColName);
            $rp->setAccessible(true);
            return $rp->isInitialized($target) ? $rp->getValue($target) : null;
        }
        if ($rc->hasProperty('id')) {
            $rp = $rc->getProperty('id');
            $rp->setAccessible(true);
            return $rp->isInitialized($target) ? $rp->getValue($target) : null;
        }
        return null;
    }

    private function shortClassName(string $cls): string
    {
        $parts = explode('\\', $cls);
        $last = end($parts);
        return lcfirst($last);
    }
}