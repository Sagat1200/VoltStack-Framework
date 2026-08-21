<?php declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\Association;

use Quantum\Database\Orm\Association\Collection\PersistentCollection;
use Quantum\Database\Orm\Association\Enum\AssociationKind;
use Quantum\Database\Orm\Association\Enum\CascadeKind;
use Quantum\Database\Orm\EntityManager\EntityManagerInterface;
use Quantum\Database\Orm\Metadata\CompiledAssociationMetadata;
use Quantum\Database\Orm\Metadata\MetadataManagerInterface;

/**
 * AssociationCascadeEngine: implementación profunda (F7).
 *
 * Llamado por UnitOfWork.computeChanges antes de calcular el grafo para:
 *   - cascadePersist: incorporar entidades asociadas cascade=PERSIST/ALL al UoW NEW.
 *   - cascadeRemove: marcar entidades asociadas cascade=REMOVE/ALL para REMOVE.
 *   - processOrphanRemoval: en colecciones con orphanRemoval=true, los items borrados
 *     del snapshot → marcados REMOVE (no solo desasociados).
 *   - synchronizeInverseCollections: OneToMany mappedBy → sincronizar owning ManyToOne
 *     en cada target ($post->author = $user al hacer $user->posts->add($post)).
 */
final class AssociationCascadeEngine
{
    public function __construct(
        private readonly MetadataManagerInterface $mm,
    ) {}

    /**
     * @return iterable<object> entidades que el caller debiera persistir (por consistencia legacy).
     */
    public function cascadePersist(object $entity, EntityManagerInterface $em, array &$visited = []): iterable
    {
        $oid = spl_object_id($entity);
        if (isset($visited[$oid])) return [];
        $visited[$oid] = true;

        $meta = $this->mm->getMetadataFor($entity::class);
        // 1) inverse sync automático (OneToMany mappedBy → target ManyToOne owner)
        $this->synchronizeInverseCollections($entity, $em);
        $added = [];
        foreach ($meta->associations as $assoc) {
            if (!$assoc->hasCascade(CascadeKind::Persist) && !$assoc->hasCascade(CascadeKind::All)) continue;
            $value = self::readAssocRaw($entity, $assoc);
            if ($value === null) continue;

            if ($value instanceof \Traversable || is_array($value)) {
                foreach ($value as $child) {
                    if (!is_object($child)) continue;
                    if (!$em->contains($child)) {
                        $em->persist($child);
                        $added[] = $child;
                    }
                    foreach ($this->cascadePersist($child, $em, $visited) as $x) $added[] = $x;
                }
            } elseif (is_object($value)) {
                if (!$em->contains($value)) {
                    $em->persist($value);
                    $added[] = $value;
                }
                foreach ($this->cascadePersist($value, $em, $visited) as $x) $added[] = $x;
            }
        }
        return $added;
    }

    /**
     * @return iterable<object> entidades que el caller debiera remove (legacy compat).
     */
    public function cascadeRemove(object $entity, EntityManagerInterface $em, array &$visited = []): iterable
    {
        $oid = spl_object_id($entity);
        if (isset($visited[$oid])) return [];
        $visited[$oid] = true;

        $meta = $this->mm->getMetadataFor($entity::class);
        $added = [];
        foreach ($meta->associations as $assoc) {
            if (!$assoc->hasCascade(CascadeKind::Remove) && !$assoc->hasCascade(CascadeKind::All)) continue;
            $value = self::readAssocRaw($entity, $assoc);
            if ($value === null) continue;

            if ($value instanceof \Traversable || is_array($value)) {
                foreach ($value as $child) {
                    if (!is_object($child)) continue;
                    $em->remove($child);
                    $added[] = $child;
                    foreach ($this->cascadeRemove($child, $em, $visited) as $x) $added[] = $x;
                }
            } elseif (is_object($value)) {
                $em->remove($value);
                $added[] = $value;
                foreach ($this->cascadeRemove($value, $em, $visited) as $x) $added[] = $x;
            }
        }
        return $added;
    }

    public function cascadeDetach(object $entity, EntityManagerInterface $em, array &$visited = []): void
    {
        $oid = spl_object_id($entity);
        if (isset($visited[$oid])) return;
        $visited[$oid] = true;

        $meta = $this->mm->getMetadataFor($entity::class);
        foreach ($meta->associations as $assoc) {
            if (!$assoc->hasCascade(CascadeKind::Detach) && !$assoc->hasCascade(CascadeKind::All)) continue;
            $value = self::readAssocRaw($entity, $assoc);
            if ($value === null) continue;

            if ($value instanceof \Traversable || is_array($value)) {
                foreach ($value as $child) {
                    if (is_object($child)) { $em->detach($child); $this->cascadeDetach($child, $em, $visited); }
                }
            } elseif (is_object($value)) {
                $em->detach($value); $this->cascadeDetach($value, $em, $visited);
            }
        }
    }

    public function cascadeRefresh(object $entity, EntityManagerInterface $em, array &$visited = []): void
    {
        $oid = spl_object_id($entity);
        if (isset($visited[$oid])) return;
        $visited[$oid] = true;

        $meta = $this->mm->getMetadataFor($entity::class);
        foreach ($meta->associations as $assoc) {
            if (!$assoc->hasCascade(CascadeKind::Refresh) && !$assoc->hasCascade(CascadeKind::All)) continue;
            $value = self::readAssocRaw($entity, $assoc);
            if ($value === null) continue;

            if ($value instanceof \Traversable || is_array($value)) {
                foreach ($value as $child) {
                    if (is_object($child)) { $this->cascadeRefresh($child, $em, $visited); }
                }
            } elseif (is_object($value)) {
                $this->cascadeRefresh($value, $em, $visited);
            }
        }
    }

    public function processOrphanRemoval(object $entity, EntityManagerInterface $em, array &$visited = []): void
    {
        $oid = spl_object_id($entity);
        if (isset($visited[$oid])) return;
        $visited[$oid] = true;

        $meta = $this->mm->getMetadataFor($entity::class);
        foreach ($meta->associations as $assoc) {
            if (!$assoc->orphanRemoval) continue;
            if ($assoc->kind === AssociationKind::OneToMany || $assoc->kind === AssociationKind::ManyToMany) {
                $coll = self::readAssocRaw($entity, $assoc);
                if ($coll instanceof PersistentCollection) {
                    $deleted = $coll->getDeleteDiff();
                    foreach ($deleted as $orphan) {
                        $em->remove($orphan);
                        $this->cascadeRemove($orphan, $em, $visited);
                    }
                }
            }
        }
    }

    /**
     * Sincronización inversa: si $entity tiene una colección OneToMany y el target
     * tiene propiedad ManyToOne indicada por mappedBy, asigna automáticamente el
     * owning side target->{mappedBy} = owner.
     *
     * Esto permite que el usuario solo haga $user->posts->add($post) y se refleje
     * automáticamente $post->author = $user antes de persist.
     */
    public function synchronizeInverseCollections(object $entity, EntityManagerInterface $em): void
    {
        $meta = $this->mm->getMetadataFor($entity::class);
        foreach ($meta->associations as $assoc) {
            if ($assoc->kind !== AssociationKind::OneToMany || $assoc->mappedBy === null) continue;
            $coll = self::readAssocRaw($entity, $assoc);
            if (!($coll instanceof \Traversable)) continue;
            $mappedByProp = $assoc->mappedBy;
            foreach ($coll as $target) {
                if (!is_object($target)) continue;
                try {
                    $rp = new \ReflectionProperty($target, $mappedByProp);
                    $rp->setAccessible(true);
                    $cur = $rp->isInitialized($target) ? $rp->getValue($target) : null;
                    if ($cur === $entity) continue;
                    $rp->setValue($target, $entity);
                } catch (\ReflectionException) {
                }
            }
        }
    }

    private static function readAssocRaw(object $entity, CompiledAssociationMetadata $assoc): mixed
    {
        try {
            $rp = new \ReflectionProperty($entity, $assoc->propertyName);
            $rp->setAccessible(true);
            return $rp->isInitialized($entity) ? $rp->getValue($entity) : null;
        } catch (\ReflectionException) {
            return null;
        }
    }
}
