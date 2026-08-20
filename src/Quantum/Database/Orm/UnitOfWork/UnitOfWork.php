<?php declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork;

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Orm\EntityManager\EntityManagerInterface;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Orm\UnitOfWork\Association\AssociationCascadeEngine;
use Quantum\Database\Orm\UnitOfWork\ChangeTracking\ChangeSet;
use Quantum\Database\Orm\UnitOfWork\ChangeTracking\SnapshotChangeTracker;
use Quantum\Database\Orm\UnitOfWork\Dependency\DependencyGraph;
use Quantum\Database\Orm\UnitOfWork\EntityPersister\EntityPersisterInterface;
use Quantum\Database\Orm\UnitOfWork\Enum\EntityState;
use Quantum\Database\Orm\UnitOfWork\Event\LifecycleEventDispatcherInterface;
use Quantum\Database\Orm\UnitOfWork\Exception\OrmException;
use Quantum\Database\Orm\UnitOfWork\Flush\FlushPlan;
use Quantum\Database\Orm\UnitOfWork\Flush\FlushStep;
use Quantum\Database\Orm\UnitOfWork\Flush\FlushStepType;

/**
 * UnitOfWork: gestiona NEW/MANAGED/REMOVED + flush en orden topológico.
 *
 * Sigue Algoritmo 6.1 (4 fases). V1 Strategy: Explicit Persist + Snapshot Change Tracking.
 */
final class UnitOfWork
{
    /** NEW list (persist llamado, flush pendiente) @var array<int,array{0:object,1:CompiledEntityMetadata}> */
    private array $newEntities = [];
    /** REMOVED list @var array<int,array{0:object,1:CompiledEntityMetadata}> */
    private array $removedEntities = [];
    /** Meta de cada spl_object_id entity class string (sin CompiledEntityMetadata para MANAGED deducir via MF) */
    /** @var array<int,CompiledEntityMetadata> spl_oid → metadata */
    private array $entityMetaByOid = [];

    public function __construct(
        public readonly IdentityMapInterface      $identityMap,
        public readonly SnapshotChangeTracker     $changeTracker,
        public readonly EntityPersisterInterface  $persister,
        private readonly AssociationCascadeEngine $cascadeEngine,
        public readonly DependencyGraph           $dependencyGraph,
        private readonly ?LifecycleEventDispatcherInterface $eventDispatcher = null,
    ) {}

    // ============== PUBLIC API ==============

    /** @throws OrmException si la entity ya está en NEW y REMOVED a la vez (UOW-003) */
    public function persist(object $entity, EntityManagerInterface $em): void
    {
        $meta = $em->getMetadataFactory()->getMetadataFor($entity::class);
        $oid = spl_object_id($entity);
        $this->entityMetaByOid[$oid] = $meta;

        // Si ya está MANAGED → no repetir NEW
        $idHash = $this->tryHashId($entity, $meta, $em);
        if ($idHash !== null && $this->identityMap->has($meta->entityClass, $idHash)) {
            return;
        }

        if (isset($this->newEntities[$oid]) && isset($this->removedEntities[$oid])) {
            throw new OrmException(
                "UOW-003: entity '{$meta->entityClass}#'{$oid} está simultáneamente en NEW y REMOVED (estado invalido)",
                'ORM_2401',
            );
        }

        $this->newEntities[$oid] = [$entity, $meta];
        unset($this->removedEntities[$oid]);

        // Cascade (V1 stub placeholder)
        foreach ($this->cascadeEngine->cascadePersist($entity, $em) as $extra) {
            if ($extra !== $entity) {
                $this->persist($extra, $em);
            }
        }
    }

    public function remove(object $entity, EntityManagerInterface $em): void
    {
        $meta = $em->getMetadataFactory()->getMetadataFor($entity::class);
        $oid = spl_object_id($entity);
        $this->entityMetaByOid[$oid] = $meta;

        unset($this->newEntities[$oid]);
        $this->removedEntities[$oid] = [$entity, $meta];

        foreach ($this->cascadeEngine->cascadeRemove($entity, $em) as $extra) {
            if ($extra !== $entity) {
                $this->remove($extra, $em);
            }
        }
    }

    /**
     * 4 fases flush. Devuelve FlushPlan para observabilidad/tests.
     */
    public function flush(EntityManagerInterface $em): FlushPlan
    {
        $conn = $em->getConnection();
        $mf = $em->getMetadataFactory();
        $tenantId = $em->getTenantId();

        $this->eventDispatcher?->dispatchPreFlush($em);

        // FASE A): Compute Changes MANAGED → ChangeSets
        /** @var array<int,array{0:object,1:CompiledEntityMetadata,2:?ChangeSet}> $pendingUpdates */
        $pendingUpdates = [];
        foreach ($this->identityMap->allWithState() as $entry) {
            /** @var EntityState $state */
            $state = $entry['state'];
            if ($state !== EntityState::MANAGED && $state !== EntityState::FLUSHING) continue;
            $e = $entry['entity'];
            $meta = $mf->getMetadataFor($e::class);
            $oid = spl_object_id($e);
            $this->entityMetaByOid[$oid] = $meta;
            $idHash = $this->hashId($e, $meta, $tenantId);
            $cs = $this->changeTracker->computeChanges($e, $meta, $idHash);
            if ($cs !== null) {
                $pendingUpdates[$oid] = [$e, $meta, $cs];
                $this->identityMap->setState($meta->entityClass, $idHash, EntityState::FLUSHING);
            }
        }

        // FASE B): Resolver NEW/MANAGED final + dependency graph edges (FK dependencias)
        $graph = $this->dependencyGraph;
        $graph->clear();
        // Para cada NEW + pendingUpdate + REMOVED → nodo = oid
        $newOids = [];
        foreach ($this->newEntities as $oid => [$e, $meta]) {
            $graph->addNode('n#' . $oid);
            $newOids[$oid] = true;
            // Inferir edges por FK a NEW entities conocidas (heurística V1: association target que está en NEW list).
            foreach ($meta->associations as $propName => $assoc) {
                if (!($assoc->kind === \Quantum\Database\Orm\Association\Enum\AssociationKind::ManyToOne
                    || $assoc->kind === \Quantum\Database\Orm\Association\Enum\AssociationKind::OneToOne)) continue;
                // solo ManyToOne / OneToOne owning-side lleva FK aquí.
                try {
                    $rp = new \ReflectionProperty($e, $propName);
                    $rp->setAccessible(true);
                    $target = $rp->getValue($e);
                } catch (\Throwable) {
                    $target = null;
                }
                if (is_object($target)) {
                    $toid = spl_object_id($target);
                    if (isset($this->newEntities[$toid])) {
                        // Edge: target → source. "target debe insertarse ANTES que source"
                        // (source es owner de la FK → requiere target PK exista).
                        // Kahn topological ordena primero nodos sin incoming edges → target.
                        $graph->addEdge('n#' . $toid, 'n#' . $oid);
                    }
                }
            }
        }
        foreach (array_keys($pendingUpdates) as $oid) {
            $graph->addNode('u#' . $oid);
        }
        foreach (array_keys($this->removedEntities) as $oid) {
            $graph->addNode('d#' . $oid);
        }

        // FASE C): Build FlushPlan
        $insertOrder = array_filter($graph->topologicalSort(), static fn($x) => str_starts_with($x, 'n#'));
        $deleteOrder = array_reverse(array_filter($graph->topologicalSort(), static fn($x) => str_starts_with($x, 'd#')));

        $steps = [];
        $i = 0;
        // Insert
        foreach ($insertOrder as $nodeId) {
            $oid = (int)substr($nodeId, 2);
            if (!isset($this->newEntities[$oid])) continue;
            [$e, $meta] = $this->newEntities[$oid];
            $steps[] = new FlushStep(type: FlushStepType::Insert, entity: $e, meta: $meta, changeSet: null, order: $i++, oid: $oid);
        }
        // Updates
        foreach ($pendingUpdates as $oid => [$e, $meta, $cs]) {
            $steps[] = new FlushStep(type: FlushStepType::Update, entity: $e, meta: $meta, changeSet: $cs, order: $i++, oid: $oid);
        }
        // Deletes (reverse topological)
        foreach ($deleteOrder as $nodeId) {
            $oid = (int)substr($nodeId, 2);
            if (!isset($this->removedEntities[$oid])) continue;
            [$e, $meta] = $this->removedEntities[$oid];
            $steps[] = new FlushStep(type: FlushStepType::Delete, entity: $e, meta: $meta, changeSet: null, order: $i++, oid: $oid);
        }
        $plan = new FlushPlan($steps);

        // FASE D): Execute TX
        $openedTx = !$conn->inTransaction();
        if ($openedTx) $conn->beginTransaction();
        try {
            foreach ($plan->steps as $step) {
                $this->executeStep($step, $conn, $em);
            }
            if ($openedTx && $conn->inTransaction()) {
                $conn->commit();
            }
            $this->eventDispatcher?->dispatchPostFlush($em);

            // Clean up
            $this->newEntities = [];
            $this->removedEntities = [];

            return $plan;
        } catch (\Throwable $e) {
            if ($openedTx && $conn->inTransaction()) {
                $conn->rollBack();
            }
            // Post-rollback cleanup: todos los entities en estado FLUSHING deben volver a MANAGED
            // y re-sincronizar snapshot (alineado al valor actual en memoria del entity).
            // Esto evita que el próximo flush detecte cambios falso-positivos y reintente el
            // mismo UPDATE/DELETE sobre entidades que ya fallaron con ex (OL, constraint, etc).
            $tenantId = $em->getTenantId();
            $mf = $em->getMetadataFactory();
            foreach ($this->identityMap->allWithState() as $entry) {
                if ($entry['state'] !== EntityState::FLUSHING) continue;
                $e2 = $entry['entity'];
                try {
                    $meta2 = $this->entityMetaByOid[spl_object_id($e2)] ?? $mf->getMetadataFor($e2::class);
                } catch (\Throwable) {
                    continue;
                }
                $idHash2 = $this->tryHashId($e2, $meta2, $em);
                if ($idHash2 !== null) {
                    try {
                        $this->changeTracker->refreshSnapshot($e2, $meta2, $idHash2);
                    } catch (\Throwable) {
                        // ignore
                    }
                    $this->identityMap->setState($meta2->entityClass, $idHash2, EntityState::MANAGED);
                }
            }
            // También limpiamos NEW y REMOVED pendientes (ya no van a ser persistidos/borrados en este intento).
            $this->newEntities = [];
            $this->removedEntities = [];
            throw $e;
        }
    }

    public function clear(?string $entityClass = null): void
    {
        $this->newEntities = [];
        $this->removedEntities = [];
        $this->entityMetaByOid = [];
        $this->identityMap->clear($entityClass);
        $this->changeTracker->clearAll();
        $this->dependencyGraph->clear();
    }

    public function contains(object $entity): bool
    {
        $oid = spl_object_id($entity);
        if (isset($this->newEntities[$oid]) || isset($this->removedEntities[$oid])) return true;
        $meta = $this->entityMetaByOid[$oid] ?? null;
        if ($meta === null) return false;
        $tenantId = null;
        $idHash = $this->tryHashId($entity, $meta, null, $tenantId);
        if ($idHash === null) return false;
        return $this->identityMap->has($meta->entityClass, $idHash);
    }

    public function detach(object $entity): void
    {
        $oid = spl_object_id($entity);
        unset($this->newEntities[$oid], $this->removedEntities[$oid]);
        $meta = $this->entityMetaByOid[$oid] ?? null;
        if ($meta === null) return;
        $idHash = $this->tryHashId($entity, $meta, null);
        if ($idHash !== null) {
            $this->identityMap->remove($meta->entityClass, $idHash);
        }
    }

    public function size(): int
    {
        return count($this->newEntities)
            + count($this->removedEntities)
            + $this->identityMap->count();
    }

    // ====================== INTERNAL ======================

    private function tryHashId(object $e, CompiledEntityMetadata $meta, ?EntityManagerInterface $em = null, ?string &$outTenant = null): ?string
    {
        // V1: IdentifierExtractor requiere constructor via F5 pero tenemos acceso al changeTracker? Mejor usar F5 IdentifierExtractor pero no la pasamos al UoW.
        // Hacemos inline: extract PK values via reflection vía metadata.
        $accessor = new \Quantum\Database\Orm\Mapping\DefaultPropertyAccessor();
        $ex = new \Quantum\Database\Orm\UnitOfWork\EntityPersister\IdentifierExtractor($accessor);
        $tenant = $em?->getTenantId();
        $outTenant = $tenant;
        try {
            if (!$ex->hasAllIds($e, $meta)) return null;
            return $ex->hashId($e, $meta, $tenant);
        } catch (\Throwable) {
            return null;
        }
    }

    private function hashId(object $e, CompiledEntityMetadata $meta, ?string $tenantId): string
    {
        $accessor = new \Quantum\Database\Orm\Mapping\DefaultPropertyAccessor();
        $ex = new \Quantum\Database\Orm\UnitOfWork\EntityPersister\IdentifierExtractor($accessor);
        return $ex->hashId($e, $meta, $tenantId);
    }

    private function executeStep(FlushStep $step, ConnectionInterface $conn, EntityManagerInterface $em): void
    {
        $tenantId = $em->getTenantId();
        switch ($step->type) {
            case FlushStepType::Insert:
                $idInserted = $this->persister->executeInsert($step->entity, $step->meta, $conn);
                $this->eventDispatcher?->dispatchPostInsert($step->entity, $step->meta, $em);
                // Post INSERT: añadir a IM state MANAGED + snapshot
                $idHash = $this->hashId($step->entity, $step->meta, $tenantId);
                $this->identityMap->set($step->meta->entityClass, $idHash, $step->entity, EntityState::MANAGED);
                $this->changeTracker->takeSnapshot($step->entity, $step->meta, $idHash);
                break;

            case FlushStepType::Update:
                $this->persister->executeUpdate($step->entity, $step->meta, $step->changeSet, $conn);
                $this->eventDispatcher?->dispatchPostUpdate($step->entity, $step->meta, $step->changeSet, $em);
                $idHashU = $this->hashId($step->entity, $step->meta, $tenantId);
                $this->changeTracker->refreshSnapshot($step->entity, $step->meta, $idHashU);
                $this->identityMap->setState($step->meta->entityClass, $idHashU, EntityState::MANAGED);
                break;

            case FlushStepType::Delete:
                $this->persister->executeDelete($step->entity, $step->meta, $conn);
                $this->eventDispatcher?->dispatchPostDelete($step->entity, $step->meta, $em);
                $idHashD = $this->tryHashId($step->entity, $step->meta, $em);
                if ($idHashD !== null) {
                    $this->identityMap->remove($step->meta->entityClass, $idHashD);
                    $this->changeTracker->removeSnapshot($step->meta, $idHashD);
                }
                break;
        }
    }
}
