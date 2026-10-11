<?php

declare(strict_types=1);

namespace Quantum\Database\ORM;

use Quantum\Database\Contracts\TransactionManagerInterface;
use Quantum\Database\ORM\Contracts\Cascade;
use Quantum\Database\ORM\Contracts\EntityManagerInterface;
use Quantum\Database\ORM\Contracts\EntityRepositoryInterface;
use Quantum\Database\ORM\Metadata\EntityAssociationMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadataRegistry;
use Quantum\Database\ORM\Planning\AssociationFetchPlanCompiler;
use Quantum\Database\ORM\Planning\CompiledHydrationPlanCache;
use Quantum\Database\ORM\Proxy\LazyLoadingPlaceholderInterface;
use Quantum\Database\ORM\Proxy\LazyLoadableEntityTrait;
use Quantum\Database\ORM\Proxy\ProxyInitializationStatus;
use Quantum\Database\ORM\Proxy\ProxyInstantiatorInterface;
use Quantum\Database\ORM\Proxy\ReflectionAnonymousProxyInstantiator;
use Quantum\Database\Query\Builder\DatabaseQueryManager;
use RuntimeException;

final class EntityManager implements EntityManagerInterface
{
    /**
     * @var array<class-string, EntityRepositoryInterface>
     */
    private array $repositories = [];

    /**
     * @var array<int, true>
     */
    private array $partialEntities = [];

    /**
     * Second-Level Cache (SLC) V1 — per-EntityManager deterministic hydration
     * cache.
     *
     * Keyed by `"$entityClass::$identifier"` (stringified) and stores the raw
     * database row (`array<string, mixed>`) as returned by the query layer.
     * The cache lives strictly within this EntityManager's scope (no shared
     * state across EntityManagers / requests) and is invalidated on every
     * write path (flushUpdate evicts, flushDelete evicts, clear wipes) and
     * refreshed on every explicit refresh().
     *
     * Purpose: cross-call-site reuse of already-loaded rows so that a
     * `find()`, `initializeProxy()` or association batch that runs *after*
     * the same identifier was loaded by a different code path (or after an
     * identity-map eviction via `clear()`) can be served without a redundant
     * SELECT. The identity map (L1) still wins for managed entities that are
     * currently tracked; the SLC only kicks in when the L1 misses.
     *
     * Only FULL rows are cached (never partial rows), so a SLC hit is always
     * safe to hydrate a complete entity.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $hydrationCache = [];

    /**
     * Per-EntityManager cache for compiled ORM plans.
     *
     * Readonly on the property level so the reference cannot be swapped; the
     * cache object itself IS mutable (it stores entries during the scope).
     */
    public readonly CompiledHydrationPlanCache $planCache;

    /**
     * Creates uninitialized lazy proxy placeholders for getReference().
     *
     * @internal Ownership lives inside this EntityManager. Not exposed as
     *           public API in this V1; swaps at construction are not supported.
     */
    private readonly ProxyInstantiatorInterface $proxyInstantiator;

    public function __construct(
        public readonly EntityMetadataRegistry $metadata,
        private readonly DatabaseQueryManager $queries,
        private readonly IdentityMap $identityMap,
        private readonly UnitOfWork $unitOfWork,
        private readonly TransactionManagerInterface $transactions,
    ) {
        $this->planCache = new CompiledHydrationPlanCache();
        $this->proxyInstantiator = new ReflectionAnonymousProxyInstantiator();
    }

    /**
     * Build the deterministic SLC key for an entity class + identifier.
     *
     * The identifier is stringified and the class FQCN is used as the
     * namespace so two entities of different classes sharing the same
     * numeric id never collide.
     *
     * @param class-string $entityClass
     */
    private function hydrationCacheKey(string $entityClass, int|string $identifier): string
    {
        return $entityClass . '::' . (string) $identifier;
    }

    /**
     * Store a full hydration row in the SLC for later cross-call-site reuse.
     *
     * MUST only be called with FULL rows (all mapped columns present). Never
     * cache partial rows — a SLC hit is hydrated verbatim and a partial row
     * would leave fields in their default/uninitialized state.
     *
     * @param class-string $entityClass
     * @param array<string, mixed> $row
     */
    private function rememberHydrationRow(string $entityClass, int|string $identifier, array $row): void
    {
        $this->hydrationCache[$this->hydrationCacheKey($entityClass, $identifier)] = $row;
    }

    /**
     * Recall a cached hydration row, or null when the identifier is absent
     * from the SLC.
     *
     * @param class-string $entityClass
     * @return array<string, mixed>|null
     */
    private function recallHydrationRow(string $entityClass, int|string $identifier): ?array
    {
        return $this->hydrationCache[$this->hydrationCacheKey($entityClass, $identifier)] ?? null;
    }

    /**
     * Remove a single SLC entry (used by write paths: update / delete).
     *
     * @param class-string $entityClass
     */
    private function evictHydrationRow(string $entityClass, int|string $identifier): void
    {
        unset($this->hydrationCache[$this->hydrationCacheKey($entityClass, $identifier)]);
    }

    public function find(string $entityClass, mixed $identifier): ?object
    {
        $metadata = $this->metadata->for($entityClass);
        $key = $metadata->keyFor($identifier);
        $managed = $this->identityMap->get($key);

        if ($managed !== null) {
            if ($this->unitOfWork->isPartialManaged($managed)) {
                $this->refresh($managed);
            }

            $this->preloadConfiguredEagerAssociations([$managed], $metadata);

            return $managed;
        }

        // SLC V1: if the same row was previously loaded in this EntityManager
        // scope (by any call site), serve it without a redundant SELECT. The
        // cached row is always a full row (see rememberHydrationRow contract),
        // so hydrateManaged is safe.
        $cachedRow = $this->recallHydrationRow($metadata->className, $key->identifier);

        if ($cachedRow !== null) {
            $entity = $this->hydrateManaged($metadata, $cachedRow);
            $this->preloadConfiguredEagerAssociations([$entity], $metadata);

            return $entity;
        }

        $row = $this->queries->table($metadata->table)
            ->where($metadata->identifier->column, $key->identifier)
            ->first();

        if ($row === null) {
            return null;
        }

        $this->rememberHydrationRow($metadata->className, $key->identifier, $row);

        $entity = $this->hydrateManaged($metadata, $row);
        $this->preloadConfiguredEagerAssociations([$entity], $metadata);

        return $entity;
    }

    public function getReference(string $entityClass, mixed $identifier): object
    {
        $metadata = $this->metadata->for($entityClass);
        $key = $metadata->keyFor($identifier);
        $managed = $this->identityMap->get($key);

        if ($managed !== null) {
            return $managed;
        }

        $placeholder = $this->proxyInstantiator->instantiatePlaceholder($metadata, $key, $this);
        $this->unitOfWork->markProxyStatus($placeholder, ProxyInitializationStatus::Uninitialized);
        $this->identityMap->register($key, $placeholder);
        $this->unitOfWork->registerManaged($placeholder, $metadata, $key);
        $this->linkLazyLoadableTraitIfUsed($placeholder, $metadata->className);

        return $placeholder;
    }

    public function initializeProxy(object $placeholder): void
    {
        if (! $placeholder instanceof LazyLoadingPlaceholderInterface
            && ! $this->unitOfWork->contains($placeholder)
        ) {
            throw new RuntimeException(sprintf(
                'Object of class [%s] is not a managed lazy proxy placeholder tracked by this EntityManager.',
                $placeholder::class,
            ));
        }

        $status = $this->unitOfWork->proxyStatusOf($placeholder);

        if ($status === ProxyInitializationStatus::Initialized) {
            return;
        }

        if ($status === ProxyInitializationStatus::Initializing) {
            throw new RuntimeException(sprintf(
                'Circular lazy-loading detected for entity [%s] while initializing the proxy placeholder.',
                $placeholder::class,
            ));
        }

        $metadata = $this->unitOfWork->metadataFor($placeholder);
        $key = $this->unitOfWork->keyFor($placeholder);

        if ($key === null) {
            throw new RuntimeException(sprintf(
                'Lazy proxy placeholder of class [%s] has no known identifier and cannot be initialized.',
                $placeholder::class,
            ));
        }

        $this->unitOfWork->markProxyStatus($placeholder, ProxyInitializationStatus::Initializing);

        try {
            // SLC V1: if the row was already loaded by another call site in
            // this scope (e.g. an association batch that hit hydrateManaged
            // and returned this same placeholder from the identity map
            // without hydrating it), populate the placeholder from the
            // cached row instead of issuing a redundant SELECT.
            $row = $this->recallHydrationRow($metadata->className, $key->identifier);

            if ($row === null) {
                $row = $this->queries->table($metadata->table)
                    ->where($metadata->identifier->column, $key->identifier)
                    ->first();

                if ($row === null) {
                    throw new RuntimeException(sprintf(
                        'Lazy proxy placeholder for entity [%s] with identifier [%s] points to a row that no longer exists.',
                        $metadata->className,
                        (string) $key->identifier,
                    ));
                }

                $this->rememberHydrationRow($metadata->className, $key->identifier, $row);
            }

            $metadata->hydrate($row, $placeholder);
            $this->unitOfWork->registerManaged($placeholder, $metadata, $key);
            $this->linkLazyLoadableTraitIfUsed($placeholder, $metadata->className);
            $this->unitOfWork->markProxyStatus($placeholder, ProxyInitializationStatus::Initialized);
            $this->dispatchLifecycle('postLoad', $placeholder);
            $this->preloadConfiguredEagerAssociations([$placeholder], $metadata);
        } catch (RuntimeException $e) {
            // Revert transient status on any failure so the caller is able to
            // retry after fixing a transient DB error (or similar). Initialized
            // status intentionally stays unmarked when the transaction fails.
            if ($this->unitOfWork->proxyStatusOf($placeholder) !== ProxyInitializationStatus::Initialized) {
                $this->unitOfWork->markProxyStatus($placeholder, ProxyInitializationStatus::Uninitialized);
            }

            throw $e;
        }
    }

    public function hydrateManaged(EntityMetadata $metadata, array $row): object
    {
        if (! array_key_exists($metadata->identifier->column, $row)) {
            throw new RuntimeException(sprintf(
                'Hydration for entity [%s] requires identifier column [%s].',
                $metadata->className,
                $metadata->identifier->column,
            ));
        }

        $key = $metadata->keyFor($row[$metadata->identifier->column]);
        $managed = $this->identityMap->get($key);

        // SLC V1: cache the full row for cross-call-site reuse even when the
        // identity map already holds an entry. This is the key enabler: a
        // batch preload that calls hydrateManaged and gets back an existing
        // Uninitialized placeholder (returned without hydration) still
        // populates the SLC, so a later initializeProxy() on that same
        // placeholder can hydrate from the cached row without a query.
        $this->rememberHydrationRow($metadata->className, $key->identifier, $row);

        if ($managed !== null) {
            return $managed;
        }

        $entity = $metadata->hydrate($row);
        $this->identityMap->register($key, $entity);
        $this->unitOfWork->registerManaged($entity, $metadata, $key);
        $this->linkLazyLoadableTraitIfUsed($entity, $metadata->className);

        $this->dispatchLifecycle('postLoad', $entity);

        return $entity;
    }

    public function hydratePartial(EntityMetadata $metadata, array $row): object
    {
        if (! array_key_exists($metadata->identifier->column, $row)) {
            throw new RuntimeException(sprintf(
                'Partial hydration for entity [%s] requires identifier column [%s].',
                $metadata->className,
                $metadata->identifier->column,
            ));
        }

        $entity = $metadata->hydrate($row);
        $this->partialEntities[spl_object_id($entity)] = true;

        return $entity;
    }

    /**
     * @param list<string> $loadedFields
     */
    public function hydrateManagedPartial(EntityMetadata $metadata, array $row, array $loadedFields): object
    {
        if (! array_key_exists($metadata->identifier->column, $row)) {
            throw new RuntimeException(sprintf(
                'Managed partial hydration for entity [%s] requires identifier column [%s].',
                $metadata->className,
                $metadata->identifier->column,
            ));
        }

        $key = $metadata->keyFor($row[$metadata->identifier->column]);
        $managed = $this->identityMap->get($key);

        if ($managed !== null) {
            if (! $this->unitOfWork->contains($managed)) {
                throw new RuntimeException(sprintf(
                    'Identity map returned entity [%s] with identifier [%s] that is not tracked by UnitOfWork.',
                    $metadata->className,
                    (string) $key->identifier,
                ));
            }

            if ($this->unitOfWork->isPartialManaged($managed)) {
                $metadata->hydrate($row, $managed);
                $this->unitOfWork->registerManagedPartial(
                    $managed,
                    $metadata,
                    $key,
                    array_values(array_unique(array_merge(
                        $this->unitOfWork->partialManagedFields($managed),
                        $loadedFields,
                    ))),
                );
                $this->linkLazyLoadableTraitIfUsed($managed, $metadata->className);
            }

            return $managed;
        }

        $entity = $metadata->hydrate($row);
        $this->identityMap->register($key, $entity);
        $this->unitOfWork->registerManagedPartial($entity, $metadata, $key, $loadedFields);
        $this->linkLazyLoadableTraitIfUsed($entity, $metadata->className);

        return $entity;
    }

    public function persist(object $entity): void
    {
        if ($this->isPartial($entity)) {
            throw new RuntimeException(sprintf(
                'Cannot persist partial entity [%s]; call refresh() or load the full entity before persisting changes.',
                $entity::class,
            ));
        }

        $metadata = $this->metadata->for($entity::class);
        $identifier = $metadata->identifierValue($entity);
        $key = $identifier !== null ? $metadata->keyFor($identifier) : null;

        if ($key !== null) {
            $managed = $this->identityMap->get($key);

            if ($managed !== null && $managed !== $entity) {
                throw new RuntimeException(sprintf(
                    'Entity [%s] is already managed with identifier [%s].',
                    $metadata->className,
                    (string) $key->identifier,
                ));
            }

            $this->identityMap->register($key, $entity);
        }

        $this->unitOfWork->persist($entity, $metadata, $key);
    }

    public function remove(object $entity): void
    {
        if ($this->isPartial($entity)) {
            throw new RuntimeException(sprintf(
                'Cannot remove partial entity [%s]; call refresh() or load the full entity before removing it.',
                $entity::class,
            ));
        }

        if ($this->unitOfWork->isPartialManaged($entity)) {
            throw new RuntimeException(sprintf(
                'Cannot remove managed partial entity [%s]; call refresh() to load the full entity before removing it.',
                $entity::class,
            ));
        }

        $this->unitOfWork->remove($entity);
    }

    public function refresh(object $entity): void
    {
        $status = $this->unitOfWork->proxyStatusOf($entity);

        if ($status === ProxyInitializationStatus::Initializing) {
            throw new RuntimeException(sprintf(
                'Circular lazy-loading detected while refreshing entity [%s].',
                $entity::class,
            ));
        }

        if ($status === ProxyInitializationStatus::Uninitialized) {
            $this->initializeProxy($entity);

            return;
        }

        $metadata = $this->metadata->for($entity::class);
        $key = $this->unitOfWork->keyFor($entity);

        if ($key === null) {
            $identifier = $metadata->identifierValue($entity);

            if ($identifier === null) {
                throw new RuntimeException('Cannot refresh an entity without an identifier.');
            }

            $key = $metadata->keyFor($identifier);
        }

        $row = $this->queries->table($metadata->table)
            ->where($metadata->identifier->column, $key->identifier)
            ->first();

        if ($row === null) {
            throw new RuntimeException(sprintf(
                'Entity [%s] with identifier [%s] no longer exists.',
                $metadata->className,
                (string) $key->identifier,
            ));
        }

        // SLC V1: refresh() ALWAYS goes to the database (semantics = reload
        // fresh state) and then refreshes the SLC entry with the latest row,
        // so subsequent finds / proxy initializations see the fresh data.
        $this->rememberHydrationRow($metadata->className, $key->identifier, $row);

        $metadata->hydrate($row, $entity);
        $this->identityMap->register($key, $entity);
        $this->unitOfWork->registerManaged($entity, $metadata, $key);
        $this->linkLazyLoadableTraitIfUsed($entity, $metadata->className);
        unset($this->partialEntities[spl_object_id($entity)]);
        $this->preloadConfiguredEagerAssociations([$entity], $metadata);
    }

    public function flush(): void
    {
        $this->assertPartialManagedCollectionChangesAreSupported();
        $this->assertNoUninitializedProxiesBeforeFlush();

        // Step 0: Apply CASCADE operations BFS (visited-guard against circular refs).
        $this->applyCascadesBeforeFlush(Cascade::PERSIST);
        $this->applyCascadesBeforeFlush(Cascade::REMOVE);

        // Step 0.1: Collect orphans (inverse OneToMany with orphanRemoval=true).
        $this->collectOrphansForRemoval();

        $operations = function (): void {
            // Insert NEW entities in dependency order so ManyToOne FK references
            // are populated before the owning side is written to the database.
            $this->flushNewEntitiesInDependencyOrder();

            foreach ($this->dirtyManagedEntities() as $entity) {
                $this->flushUpdate($entity);
            }

            $this->flushManyToManyMembershipChanges();
            $this->refreshPartialManagedCollectionSnapshots();

            foreach ($this->unitOfWork->removedEntities() as $entity) {
                $this->flushDelete($entity);
            }
        };

        if (
            $this->unitOfWork->newEntities() === []
            && $this->dirtyManagedEntities() === []
            && ! $this->hasPendingManyToManyMembershipChanges()
            && $this->unitOfWork->removedEntities() === []
        ) {
            return;
        }

        $this->transactions->transaction($operations);
    }

    public function clear(): void
    {
        $this->repositories = [];
        $this->partialEntities = [];
        // SLC V1: the Second-Level Cache deliberately SURVIVES clear(). clear()
        // only evicts the L1 (identity map + UnitOfWork); the SLC is the
        // layer that provides cross-call-site reuse exactly when the L1 has been
        // cleared. Writes (flushUpdate/flushDelete) and refresh() keep the SLC
        // consistent; raw out-of-band DB mutations are the caller's
        // responsibility (standard L2-cache semantics).
        $this->identityMap->clear();
        $this->unitOfWork->clear();
    }

    public function contains(object $entity): bool
    {
        return $this->unitOfWork->contains($entity);
    }

    /**
     * Public passthrough for opt-in lazy-load interceptors to read the 3-phase
     * proxy initialization status of a managed entity without leaking the
     * internal UnitOfWork reference.
     */
    public function proxyStatusOf(object $entity): ProxyInitializationStatus
    {
        return $this->unitOfWork->proxyStatusOf($entity);
    }

    /**
     * If the entity uses `LazyLoadableEntityTrait` (opt-in user-land),
     * inject the 2 runtime state cells needed by the magic interceptors:
     * a WeakReference back to this EntityManager (so the entity never
     * prevents GC of the EM — avoids circular-reference leaks) and the
     * entity FQCN cached for stable lookups inside the trait context.
     *
     * Called immediately after every successful `registerManaged()` and
     * `registerManagedPartial()` in this EntityManager so the link is
     * guaranteed to be alive before any user code reads back the entity.
     *
     * The call is idempotent; repeated invocations overwrite with the same
     * values and are harmless (covers refresh / initializeProxy re-entry).
     *
     * @param class-string $className
     */
    private function linkLazyLoadableTraitIfUsed(object $entity, string $className): void
    {
        // Multi-level inheritance-safe trait detection. `class_uses()` only
        // reports traits directly used by the exact class, so walk up the
        // inheritance chain to cover traits introduced by parent classes.
        $usesTrait = false;
        for ($c = $className; $c !== false; $c = get_parent_class($c)) {
            foreach (class_uses($c) as $t) {
                if (ltrim($t, '\\') === LazyLoadableEntityTrait::class) {
                    $usesTrait = true;
                    break 2;
                }
            }
        }

        if (! $usesTrait) {
            return;
        }

        $refWeak = new \ReflectionProperty($entity, '__ormEntityManagerWeakRef');
        $refWeak->setAccessible(true);
        $refWeak->setValue($entity, \WeakReference::create($this));

        $refClass = new \ReflectionProperty($entity, '__ormEntityClassName');
        $refClass->setAccessible(true);
        $refClass->setValue($entity, $className);

        // For `__get` / `__set` on the trait to be invoked, PHP requires that
        // the target property NOT exist on the object's property table (on
        // public declared properties PHP skips magic methods and accesses
        // storage directly). So we selectively unset() mapped properties
        // depending on proxy status:
        //
        //   * Uninitialized placeholders -> every non-id mapped field and
        //     every association is removed so ANY mapped-property access
        //     routes through the trait (hydrate-through + guardrails).
        //   * Initialized managed entities -> only lazy associations (those
        //     with fetch:lazy and not yet materialized) are removed, so the
        //     first ->assoc read goes through the trait's loader and
        //     subsequent reads use the PHP-native shadowed property.
        //
        // The trait always passes through for transient properties that are
        // not in the metadata map, keeping regular PHP semantics.
        $metadata = $this->unitOfWork->contains($entity)
            ? $this->unitOfWork->metadataFor($entity)
            : $this->metadata->for($className);

        $status = $this->proxyStatusOf($entity);
        $identifierName = $metadata->identifier->name;

        if ($status === ProxyInitializationStatus::Uninitialized) {
            // All mapped non-id fields (columns + embeddeds) + all associations.
            foreach ($metadata->fields as $fieldName => $_field) {
                if ($fieldName === $identifierName) {
                    continue;
                }
                self::unsafeUnsetPublicProperty($entity, $fieldName);
            }
            foreach ($metadata->embeddeds as $embeddedName => $_embedded) {
                self::unsafeUnsetPublicProperty($entity, $embeddedName);
            }
            foreach ($metadata->associations as $assocName => $_assoc) {
                self::unsafeUnsetPublicProperty($entity, $assocName);
            }
        } else {
            // Initialized (or unknown status). Only unset LAZY associations.
            foreach ($metadata->associations as $assocName => $assoc) {
                if (! $assoc->isLazy()) {
                    continue;
                }

                // Before unsetting, check whether the property actually holds
                // a non-default/non-empty value. If the user has explicitly
                // populated it (e.g. preload during initializeProxy eager, or
                // user-side sideload between calls), we preserve the value.
                $vars = get_object_vars($entity);
                $materialized = array_key_exists($assocName, $vars)
                    && $vars[$assocName] !== null
                    && ($assoc->isToOne() || $vars[$assocName] !== []);
                if ($materialized) {
                    continue;
                }

                self::unsafeUnsetPublicProperty($entity, $assocName);
            }
        }
    }

    /**
     * Unset a declared PUBLIC property on an entity instance without
     * triggering __unset() or failing on properties that are already gone.
     *
     * The entity POPO convention used across the ORM (and all bundled
     * fixtures) is public-declared fields and associations; this helper does
     * NOT attempt to reach into private/protected state because the trait's
     * fast-path property_exists / get_object_vars checks would not be able
     * to read them anyway.
     *
     * @param non-empty-string $name
     */
    private static function unsafeUnsetPublicProperty(object $entity, string $name): void
    {
        try {
            $reflection = new \ReflectionProperty($entity, $name);
        } catch (\ReflectionException) {
            // Dynamically-added or unknown property. If it exists on the
            // instance as a dynamic member unset() will clear it; otherwise
            // it is a no-op.
            try {
                unset($entity->$name);
            } catch (\Throwable) {
                // @ignoreException
            }

            return;
        }

        if (! $reflection->isPublic()) {
            // Trait/protected/private: reflection-based unset not supported
            // in this helper. Safe no-op: the magic method handling cannot
            // surface through anyway (visibility rules win over __get).
            return;
        }

        try {
            // Accessible public property via direct unset on the object works
            // for public visibility regardless of scope (unset() can unset a
            // public member from outside scope).
            unset($entity->$name);
        } catch (\Throwable) {
            // @ignoreException — PHP edge case (readonly, error handler, etc.)
        }
    }

    public function snapshotCollections(object $entity): void
    {
        if (! $this->unitOfWork->contains($entity)) {
            return;
        }

        $this->unitOfWork->snapshotOneToManyCollections($entity, $this->unitOfWork->metadataFor($entity));
    }

    /**
     * Reject any flush operation that would require a real snapshot for a
     * placeholder that is still Uninitialized. Covers BOTH lazy placeholders
     * returned by getReference() and entities explicitly implementing the
     * marker interface before an explicit initializeProxy() call.
     *
     * The same single message / guard helper is used by loaders and per-entity
     * flush steps to keep DX unified.
     */
    private function assertNoUninitializedProxiesBeforeFlush(): void
    {
        foreach ($this->unitOfWork->newEntities() as $entity) {
            $this->assertNoUninitializedProxyAccess($entity, 'flush / cascade');
        }

        foreach ($this->unitOfWork->managedEntities() as $entity) {
            $this->assertNoUninitializedProxyAccess($entity, 'flush / dirty-check / orphan-removal');
        }

        foreach ($this->unitOfWork->removedEntities() as $entity) {
            $this->assertNoUninitializedProxyAccess($entity, 'flush / remove');
        }
    }

    private function assertNoUninitializedProxyAccess(object $entity, string $operation): void
    {
        if (! $this->unitOfWork->isUninitializedProxy($entity)) {
            return;
        }

        $metadata = $this->unitOfWork->contains($entity)
            ? $this->unitOfWork->metadataFor($entity)
            : $this->metadata->for($entity::class);

        $identifier = $this->unitOfWork->keyFor($entity)?->identifier
            ?? $metadata->identifierValue($entity)
            ?? '<unknown>';

        throw new RuntimeException(sprintf(
            'Entity [%s] with identifier [%s] is an uninitialized proxy placeholder. Call EntityManager::initializeProxy($entity) or EntityManager::refresh($entity) before accessing its persisted state or triggering operations (%s) that require snapshots.',
            $metadata->className,
            (string) $identifier,
            $operation,
        ));
    }

    public function isPartial(object $entity): bool
    {
        return isset($this->partialEntities[spl_object_id($entity)]);
    }

    public function state(object $entity): EntityState
    {
        return $this->unitOfWork->state($entity);
    }

    public function repository(string $entityClass): EntityRepositoryInterface
    {
        if (isset($this->repositories[$entityClass])) {
            return $this->repositories[$entityClass];
        }

        $metadata = $this->metadata->for($entityClass);
        $repositoryClass = $metadata->repositoryClass;

        if ($repositoryClass !== null) {
            $repository = new $repositoryClass($this, $metadata);

            if (! $repository instanceof EntityRepositoryInterface) {
                throw new RuntimeException(sprintf(
                    'Repository [%s] must implement [%s].',
                    $repositoryClass,
                    EntityRepositoryInterface::class,
                ));
            }

            return $this->repositories[$entityClass] = $repository;
        }

        return $this->repositories[$entityClass] = new EntityRepository($this, $metadata);
    }

    public function query(string $entityClass): EntityQuery
    {
        return new EntityQuery(
            $this,
            $this->metadata->for($entityClass),
            $this->queries,
        );
    }

    /**
     * @param list<object> $entities
     * @param list<string> $associationNames
     */
    public function preloadAssociations(array $entities, array $associationNames): void
    {
        if ($entities === [] || $associationNames === []) {
            return;
        }

        $first = $entities[0];
        $metadata = $this->metadata->for($first::class);

        foreach ($entities as $entity) {
            if (! $entity instanceof $metadata->className) {
                throw new RuntimeException(sprintf(
                    'Cannot preload associations for mixed entity types; expected [%s], got [%s].',
                    $metadata->className,
                    $entity::class,
                ));
            }
        }

        $groupedPaths = $this->planCache->rememberGroupedAssociationPaths(
            $metadata,
            $associationNames,
            fn() => $this->fetchPlanCompiler()->groupPaths($metadata, $associationNames),
        );

        foreach ($groupedPaths as $associationName => $nestedAssociationPaths) {
            $loadedTargets = $this->preloadAssociationBatch(
                $entities,
                $metadata,
                $metadata->association($associationName),
            );
            $this->preloadConfiguredEagerAssociationsOnTargets(
                $loadedTargets,
                $metadata->association($associationName),
            );

            if ($nestedAssociationPaths !== []) {
                $this->preloadAssociations(
                    $this->uniqueEntities($loadedTargets),
                    $nestedAssociationPaths,
                );
            }
        }
    }

    /**
     * @param list<object> $entities
     */
    private function preloadConfiguredEagerAssociations(array $entities, EntityMetadata $metadata): void
    {
        $this->preloadAssociations($entities, $metadata->eagerAssociationNames());
    }

    private function fetchPlanCompiler(): AssociationFetchPlanCompiler
    {
        return new AssociationFetchPlanCompiler($this->metadata);
    }

    /**
     * @param list<object> $entities
     * @return list<object>
     */
    private function uniqueEntities(array $entities): array
    {
        $unique = [];
        $seen = [];

        foreach ($entities as $entity) {
            $objectId = spl_object_id($entity);
            if (isset($seen[$objectId])) {
                continue;
            }

            $seen[$objectId] = true;
            $unique[] = $entity;
        }

        return $unique;
    }

    /**
     * @param list<object> $targets
     */
    private function preloadConfiguredEagerAssociationsOnTargets(
        array $targets,
        EntityAssociationMetadata $loadedAssociation,
    ): void {
        if ($targets === []) {
            return;
        }

        $targetMetadata = $this->metadata->for($loadedAssociation->targetEntity);
        $associationNames = $targetMetadata->eagerAssociationNames();
        if ($associationNames === []) {
            return;
        }

        $reverseAssociation = $loadedAssociation->mappedBy ?? $loadedAssociation->inversedBy;
        if ($reverseAssociation !== null) {
            $associationNames = array_values(array_filter(
                $associationNames,
                static fn(string $name): bool => $name !== $reverseAssociation,
            ));
        }

        if ($associationNames === []) {
            return;
        }

        $this->preloadAssociationsWithoutEagerCascade($targets, $associationNames);
    }

    /**
     * @param list<object> $entities
     * @param list<string> $associationNames
     */
    private function preloadAssociationsWithoutEagerCascade(array $entities, array $associationNames): void
    {
        if ($entities === [] || $associationNames === []) {
            return;
        }

        $first = $entities[0];
        $metadata = $this->metadata->for($first::class);

        foreach ($entities as $entity) {
            if (! $entity instanceof $metadata->className) {
                throw new RuntimeException(sprintf(
                    'Cannot preload associations for mixed entity types; expected [%s], got [%s].',
                    $metadata->className,
                    $entity::class,
                ));
            }
        }

        foreach ($associationNames as $associationName) {
            $this->preloadAssociationBatch(
                $entities,
                $metadata,
                $metadata->association($associationName),
            );
        }
    }

    public function loadToOne(object $entity, string $associationName): ?object
    {
        $metadata = $this->metadata->for($entity::class);
        $association = $metadata->association($associationName);

        if (! $association->isToOne()) {
            throw new RuntimeException(sprintf(
                'Association [%s::$%s] is not a to-one relationship.',
                $metadata->className,
                $associationName,
            ));
        }

        // Batch-rescue V1 (DV-DB-039): when the user lazily accesses a to-one
        // association on this entity, scan every other *managed entity of the
        // same class* whose association property is still unset (i.e. it went
        // through `linkLazyLoadableTraitIfUsed` and was therefore flagged as
        // not-yet-materialized) and load ALL of them in a single batched query
        // via the already-tested `preloadAssociationBatch` path. This turns
        // the classic N+1 lazy-access anti-pattern into a 1-query rescue
        // whenever siblings are present in the same scope.
        $group = $this->rescueBatchGroup($entity, $metadata, $association);
        $this->preloadAssociationBatch($group, $metadata, $association);

        return $entity->{$associationName};
    }

    /**
     * @return list<object>
     */
    public function loadToMany(object $entity, string $associationName): array
    {
        $metadata = $this->metadata->for($entity::class);
        $association = $metadata->association($associationName);

        if (! $association->isToMany()) {
            throw new RuntimeException(sprintf(
                'Association [%s::$%s] is not a to-many relationship.',
                $metadata->className,
                $associationName,
            ));
        }

        // Same batch-rescue strategy as loadToOne (see comment above). For a
        // to-many association the rescue is especially valuable because each
        // lazy collection load used to cost its own SELECT; now the first
        // access on any one of the sibling entities hydrates every sibling's
        // collection in a single batched SELECT.
        $group = $this->rescueBatchGroup($entity, $metadata, $association);
        $this->preloadAssociationBatch($group, $metadata, $association);

        return $entity->{$associationName};
    }

    /**
     * Build the rescue group for a lazy association access.
     *
     * Always includes the triggering entity. Additionally includes every other
     * managed entity of the *same class* that:
     *   - is in Initialized proxy state (Uninitialized placeholders are
     *     excluded: `preloadAssociationBatch` would throw the uninitialized
     *     guardrail on them, and they cannot contribute an FK/identifier yet),
     *   - and whose association property is NOT present in the object's
     *     property table (`unset()` by `linkLazyLoadableTraitIfUsed`), which
     *     is the canonical signal that the lazy association has not been
     *     materialized yet. Entities without the trait (or whose association
     *     was already loaded / explicitly sideloaded) keep their value as-is
     *     and are NOT touched — preserving 100% backward compatibility.
     *
     * @return list<object>
     */
    private function rescueBatchGroup(
        object $entity,
        EntityMetadata $metadata,
        EntityAssociationMetadata $association,
    ): array {
        $group = [$entity];
        $seen = [spl_object_id($entity) => true];

        foreach ($this->unitOfWork->managedEntities() as $sibling) {
            $oid = spl_object_id($sibling);
            if (isset($seen[$oid])) {
                continue;
            }

            if ($sibling::class !== $metadata->className) {
                continue;
            }

            // Only rescue fully-initialized siblings. Uninitialized
            // placeholders cannot participate in a batched FK/identifier
            // load and would trip the uninitialized guardrail inside
            // `preloadAssociationBatch`.
            if ($this->proxyStatusOf($sibling) !== ProxyInitializationStatus::Initialized) {
                continue;
            }

            // Canonical "not yet materialized" signal: the association
            // property was unset by the trait wiring. If the property is
            // present (no trait, or already loaded/sideloaded) we leave it
            // untouched — no overwrite, no surprise.
            if (array_key_exists($association->name, get_object_vars($sibling))) {
                continue;
            }

            $group[] = $sibling;
            $seen[$oid] = true;
        }

        return $group;
    }

    /**
     * @param list<object> $entities
     */
    private function preloadAssociationBatch(
        array $entities,
        EntityMetadata $metadata,
        EntityAssociationMetadata $association,
    ): array {
        if ($entities === []) {
            return [];
        }

        foreach ($entities as $entity) {
            $this->assertNoUninitializedProxyAccess(
                $entity,
                sprintf('preloadAssociationBatch [%s::$%s]', $metadata->className, $association->name),
            );
        }

        if ($association->isToOne()) {
            if ($association->isOwningSide()) {
                return $this->preloadOwningToOneAssociation($entities, $association);
            } else {
                return $this->preloadInverseOneToOneAssociation($entities, $metadata, $association);
            }
        }

        if ($association->isManyToMany()) {
            return $this->preloadManyToManyAssociation($entities, $metadata, $association);
        }

        return $this->preloadOneToManyAssociation($entities, $metadata, $association);
    }

    /**
     * @param list<object> $entities
     * @return list<object>
     */
    private function preloadOwningToOneAssociation(array $entities, EntityAssociationMetadata $association): array
    {
        $foreignKeys = [];
        foreach ($entities as $entity) {
            $foreignKey = $this->associationSourceValue($entity, $association);
            if ($foreignKey === null) {
                $this->assignAssociationValue($entity, $association, null);
                continue;
            }

            $foreignKeys[(string) $foreignKey] = $foreignKey;
        }

        if ($foreignKeys === []) {
            return [];
        }

        $targetsById = $this->fetchEntitiesByIdentifiers(
            $this->metadata->for($association->targetEntity),
            array_values($foreignKeys),
        );

        foreach ($entities as $entity) {
            $foreignKey = $this->associationSourceValue($entity, $association);
            if ($foreignKey === null) {
                continue;
            }

            $this->assignAssociationValue(
                $entity,
                $association,
                $targetsById[(string) $foreignKey] ?? null,
            );
        }

        return $this->uniqueObjects(array_values($targetsById));
    }

    /**
     * @param list<object> $entities
     * @return list<object>
     */
    private function preloadInverseOneToOneAssociation(
        array $entities,
        EntityMetadata $metadata,
        EntityAssociationMetadata $association,
    ): array {
        if ($association->targetColumn === null) {
            throw new RuntimeException(sprintf(
                'Association [%s::$%s] has no resolved target column for inverse to-one preload.',
                $metadata->className,
                $association->name,
            ));
        }

        $entitiesById = $this->mapEntitiesByIdentifier($entities, $metadata);
        if ($entitiesById === []) {
            return [];
        }

        $targetMetadata = $this->metadata->for($association->targetEntity);
        $rows = $this->queries->table($targetMetadata->table)
            ->whereIn($association->targetColumn, array_values(array_map(
                static fn(string $identifier): string => $identifier,
                array_keys($entitiesById),
            )))
            ->get()
            ->rows();

        $loadedBySourceId = [];
        foreach ($rows as $row) {
            $sourceId = $row[$association->targetColumn] ?? null;
            if ($sourceId === null || $sourceId === '') {
                continue;
            }

            $target = $this->hydrateManaged($targetMetadata, $row);
            $loadedBySourceId[(string) $sourceId] = $target;

            if ($association->mappedBy !== null && $targetMetadata->hasAssociation($association->mappedBy)) {
                $this->assignAssociationValue(
                    $target,
                    $targetMetadata->association($association->mappedBy),
                    $entitiesById[(string) $sourceId] ?? null,
                );
            }
        }

        foreach ($entitiesById as $identifier => $entity) {
            $this->assignAssociationValue($entity, $association, $loadedBySourceId[$identifier] ?? null);
        }

        return $this->uniqueObjects(array_values($loadedBySourceId));
    }

    /**
     * @param list<object> $entities
     * @return list<object>
     */
    private function preloadOneToManyAssociation(
        array $entities,
        EntityMetadata $metadata,
        EntityAssociationMetadata $association,
    ): array {
        if ($association->targetColumn === null) {
            throw new RuntimeException(sprintf(
                'Association [%s::$%s] has no resolved target column for to-many preload.',
                $metadata->className,
                $association->name,
            ));
        }

        $entitiesById = $this->mapEntitiesByIdentifier($entities, $metadata);
        if ($entitiesById === []) {
            return [];
        }

        $targetMetadata = $this->metadata->for($association->targetEntity);
        $rows = $this->queries->table($targetMetadata->table)
            ->whereIn($association->targetColumn, array_values(array_map(
                static fn(string $identifier): string => $identifier,
                array_keys($entitiesById),
            )))
            ->get()
            ->rows();

        $grouped = [];
        foreach ($rows as $row) {
            $sourceId = $row[$association->targetColumn] ?? null;
            if ($sourceId === null || $sourceId === '') {
                continue;
            }

            $target = $this->hydrateManaged($targetMetadata, $row);
            $key = (string) $sourceId;
            $grouped[$key] ??= [];
            $grouped[$key][] = $target;

            if ($association->mappedBy !== null && $targetMetadata->hasAssociation($association->mappedBy)) {
                $this->assignAssociationValue(
                    $target,
                    $targetMetadata->association($association->mappedBy),
                    $entitiesById[$key] ?? null,
                );
            }
        }

        foreach ($entitiesById as $identifier => $entity) {
            $loaded = $grouped[$identifier] ?? [];
            $this->assignAssociationValue($entity, $association, $loaded);
            if ($this->contains($entity)) {
                $this->unitOfWork->snapshotOneToManyCollections($entity, $metadata);
            }
        }

        if ($grouped === []) {
            return [];
        }

        return $this->uniqueObjects(array_merge(...array_values($grouped)));
    }

    /**
     * @param list<object> $entities
     * @return list<object>
     */
    private function preloadManyToManyAssociation(
        array $entities,
        EntityMetadata $metadata,
        EntityAssociationMetadata $association,
    ): array {
        if (! $association->usesJoinTable()) {
            throw new RuntimeException(sprintf(
                'ManyToMany association [%s::$%s] is missing JoinTable metadata.',
                $metadata->className,
                $association->name,
            ));
        }

        $entitiesById = $this->mapEntitiesByIdentifier($entities, $metadata);
        if ($entitiesById === []) {
            return [];
        }

        $membershipRows = $this->queries->table($association->joinTable)
            ->whereIn($association->joinTableSourceColumn, array_values(array_map(
                static fn(string $identifier): string => $identifier,
                array_keys($entitiesById),
            )))
            ->get()
            ->rows();

        $targetIds = [];
        foreach ($membershipRows as $row) {
            $targetId = $row[$association->joinTableTargetColumn] ?? null;
            if ($targetId === null || $targetId === '') {
                continue;
            }

            $targetIds[(string) $targetId] = $targetId;
        }

        $targetsById = $this->fetchEntitiesByIdentifiers(
            $this->metadata->for($association->targetEntity),
            array_values($targetIds),
        );

        $grouped = [];
        foreach ($membershipRows as $row) {
            $sourceId = $row[$association->joinTableSourceColumn] ?? null;
            $targetId = $row[$association->joinTableTargetColumn] ?? null;
            if ($sourceId === null || $sourceId === '' || $targetId === null || $targetId === '') {
                continue;
            }

            $sourceKey = (string) $sourceId;
            $targetKey = (string) $targetId;
            if (! isset($targetsById[$targetKey])) {
                continue;
            }

            $grouped[$sourceKey] ??= [];
            $grouped[$sourceKey][] = $targetsById[$targetKey];
        }

        foreach ($entitiesById as $identifier => $entity) {
            $loaded = $grouped[$identifier] ?? [];
            $this->assignAssociationValue($entity, $association, $loaded);
            if ($this->contains($entity)) {
                $this->unitOfWork->snapshotOneToManyCollections($entity, $metadata);
            }
        }

        return $this->uniqueObjects(array_values($targetsById));
    }

    private function flushInsert(object $entity): void
    {
        $metadata = $this->unitOfWork->metadataFor($entity);

        $this->dispatchLifecycle('prePersist', $entity);
        $this->populateManyToOneForeignKeys($entity, $metadata);

        $values = $metadata->extractForWrite(
            $entity,
            includeIdentifier: $metadata->identifierValue($entity) !== null,
        );

        $result = $this->queries->table($metadata->table)->insert($values);
        $identifier = $metadata->identifierValue($entity);

        if ($identifier === null && $result->lastInsertId !== null && $result->lastInsertId !== '') {
            $metadata->assignIdentifier($entity, $result->lastInsertId);
            $identifier = $metadata->identifierValue($entity);
        }

        if ($identifier === null) {
            throw new RuntimeException(sprintf(
                'Inserted entity [%s] did not expose an identifier after flush.',
                $metadata->className,
            ));
        }

        $key = $metadata->keyFor($identifier);
        $this->identityMap->register($key, $entity);
        $this->unitOfWork->synchronize($entity, $key);

        // SLC V1: cache the just-inserted row so a subsequent find() after an
        // identity-map eviction (clear()) can serve it without a SELECT. The
        // inserted values use DB column names; ensure the identifier column is
        // present (it may be auto-generated and absent from $values).
        $cachedRow = $values;
        $cachedRow[$metadata->identifier->column] = $key->identifier;
        $this->rememberHydrationRow($metadata->className, $key->identifier, $cachedRow);

        $this->dispatchLifecycle('postPersist', $entity);
    }

    private function flushUpdate(object $entity): void
    {
        $this->assertNoUninitializedProxyAccess($entity, 'flushUpdate');

        $metadata = $this->unitOfWork->metadataFor($entity);
        $key = $this->requireKey($entity);

        $this->populateManyToOneForeignKeys($entity, $metadata);

        $changes = [];
        $current = $this->trackedCurrentValues($entity, $metadata);
        $original = $this->unitOfWork->snapshot($entity);
        unset($original[$metadata->identifier->name]);

        $allFields = $this->unitOfWork->isPartialManaged($entity)
            ? array_values(array_unique(array_merge(
                $this->partialManagedRootFieldNames($metadata, $this->unitOfWork->partialManagedFields($entity)),
                array_keys($current),
                array_keys($original),
            )))
            : array_values(array_unique(array_merge(array_keys($current), array_keys($original))));

        foreach ($allFields as $field) {
            if ($field === $metadata->identifier->name) {
                continue;
            }

            $currentHasKey = array_key_exists($field, $current);
            $originalHasKey = array_key_exists($field, $original);
            $currentValue = $currentHasKey ? $current[$field] : null;
            $originalValue = $originalHasKey ? $original[$field] : null;

            if ($currentHasKey && $originalHasKey && $originalValue === $currentValue) {
                continue;
            }

            if ($metadata->hasField($field)) {
                $fieldMeta = $metadata->field($field);
                $changes[$fieldMeta->column] = $fieldMeta->databaseValueFrom($currentValue);

                continue;
            }

            if (str_contains($field, '.')) {
                [$embeddedName, $innerName] = explode('.', $field, 2);

                if ($metadata->hasEmbedded($embeddedName)) {
                    $embedded = $metadata->embedded($embeddedName);
                    if ($embedded->hasInnerField($innerName)) {
                        $innerField = $embedded->innerField($innerName);
                        $changes[$innerField->column] = $innerField->databaseValueFrom($currentValue);

                        continue;
                    }
                }
            }

            throw new RuntimeException(sprintf(
                'Cannot compute update change for entity [%s] unknown field [%s].',
                $metadata->className,
                $field,
            ));
        }

        if ($changes === []) {
            $this->synchronizeTrackingState($entity, $key);

            return;
        }

        $currentIdentifier = $metadata->identifierValue($entity);

        if ($currentIdentifier !== $key->identifier) {
            throw new RuntimeException(sprintf(
                'Entity [%s] identifier mutation is not supported.',
                $metadata->className,
            ));
        }

        $this->dispatchLifecycle('preUpdate', $entity, [
            'changes' => $changes,
            'currentValues' => $current,
            'originalSnapshot' => $original,
        ]);

        $this->queries->table($metadata->table)
            ->where($metadata->identifier->column, $key->identifier)
            ->update($changes);

        // SLC V1: the persisted row in the cache is now stale. Evict it so
        // any future lookup that misses the identity map (e.g. after clear())
        // re-queries the database for the freshly persisted state. The
        // identity map still holds the managed entity, so find() within this
        // scope keeps returning it without a query.
        $this->evictHydrationRow($metadata->className, $key->identifier);

        $this->synchronizeTrackingState($entity, $key);

        $this->dispatchLifecycle('postUpdate', $entity, [
            'changes' => $changes,
            'currentValues' => $current,
        ]);
    }

    private function flushDelete(object $entity): void
    {
        $metadata = $this->unitOfWork->metadataFor($entity);
        $key = $this->requireKey($entity);

        $this->dispatchLifecycle('preRemove', $entity);
        $this->deleteManyToManyMembershipsFor($entity, $metadata, $key->identifier);

        $this->queries->table($metadata->table)
            ->where($metadata->identifier->column, $key->identifier)
            ->delete();

        // SLC V1: the row no longer exists in the database; drop the cached
        // entry so a subsequent find() correctly returns null instead of
        // serving a ghost row.
        $this->evictHydrationRow($metadata->className, $key->identifier);

        $this->dispatchLifecycle('postRemove', $entity);

        $this->identityMap->remove($key);
        $this->unitOfWork->detach($entity);
    }

    /**
     * @return list<object>
     */
    private function dirtyManagedEntities(): array
    {
        $dirty = [];

        foreach ($this->unitOfWork->managedEntities() as $entity) {
            $metadata = $this->unitOfWork->metadataFor($entity);
            if ($this->unitOfWork->isPartialManaged($entity)) {
                $current = $this->trackedCurrentValues($entity, $metadata);
                $original = $this->unitOfWork->snapshot($entity);

                if ($current !== $original) {
                    $dirty[] = $entity;
                }

                continue;
            }

            $this->populateManyToOneForeignKeys($entity, $metadata);
            $current = $metadata->extract($entity, includeIdentifier: false);
            $original = $this->unitOfWork->snapshot($entity);
            unset($original[$metadata->identifier->name]);

            if ($current !== $original) {
                $dirty[] = $entity;
            }
        }

        return $dirty;
    }

    private function requireKey(object $entity): EntityKey
    {
        $key = $this->unitOfWork->keyFor($entity);

        if ($key === null) {
            throw new RuntimeException('A persistent entity key is required for this operation.');
        }

        return $key;
    }

    private function hasPendingManyToManyMembershipChanges(): bool
    {
        foreach ($this->unitOfWork->managedEntities() as $entity) {
            $metadata = $this->unitOfWork->metadataFor($entity);
            $key = $this->unitOfWork->keyFor($entity);
            if ($key === null) {
                continue;
            }

            foreach ($this->manyToManyAssociationsToSynchronize($entity, $metadata) as $association) {
                if (
                    $this->currentManyToManyTargetIds($entity, $association)
                    !== $this->existingManyToManyTargetIds($association, $key->identifier)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function assertPartialManagedCollectionChangesAreSupported(): void
    {
        foreach ($this->unitOfWork->managedEntities() as $entity) {
            if (! $this->unitOfWork->isPartialManaged($entity)) {
                continue;
            }

            $metadata = $this->unitOfWork->metadataFor($entity);
            foreach ($this->unitOfWork->loadedPartialManagedToManyAssociations($entity) as $associationName) {
                $association = $metadata->association($associationName);
                $diff = $this->unitOfWork->collectionDiff($entity, $associationName);
                if ($diff['removed'] === [] && $diff['added'] === []) {
                    continue;
                }

                if ($association->isManyToMany() && ! $association->isOwningSide()) {
                    throw new RuntimeException(sprintf(
                        'Cannot mutate inverse ManyToMany partially managed collection [%s::$%s]; mutate the owning-side collection instead.',
                        $metadata->className,
                        $associationName,
                    ));
                }

                if ($association->isOneToMany()) {
                    $this->assertPartialManagedOneToManyMutationIsSynchronizable($entity, $association, $diff);
                }
            }
        }
    }

    /**
     * Internal lifecycle dispatcher. Invokes, in order:
     *   (1) method-level entity #[Pre* / Post*] attribute callbacks
     *   (2) class-level listeners registered via #[Entity(lifecycleListeners: [X::class])]
     *
     * Callbacks stored on EntityMetadata already receive (entity, em, context) as
     * their argument list, so this routine is a simple linear traversal.
     *
     * @param array<string, mixed> $context
     */
    private function dispatchLifecycle(string $event, object $entity, array $context = []): void
    {
        $metadata = $this->metadata->for($entity::class);

        if (! $metadata->hasCallbacks($event)) {
            return;
        }

        foreach ($metadata->callbacksFor($event) as $callback) {
            $callback($entity, $this, $context);
        }
    }

    /**
     * Apply cascade operations BFS (persist/remove) across the association graph
     * for every entity currently enqueued in the corresponding UoW states.
     *
     * Uses a visited spl_object_id set to prevent infinite recursion when
     * bidirectional associations both declare cascade on the same operation.
     *
     * @param string $operation One of Cascade::PERSIST or Cascade::REMOVE.
     */
    private function applyCascadesBeforeFlush(string $operation): void
    {
        if ($operation !== Cascade::PERSIST && $operation !== Cascade::REMOVE) {
            throw new RuntimeException(sprintf(
                'applyCascadesBeforeFlush only supports %s or %s; got [%s].',
                Cascade::PERSIST,
                Cascade::REMOVE,
                $operation,
            ));
        }

        /** @var list<object> $queue */
        $queue = $operation === Cascade::PERSIST
            ? array_merge($this->unitOfWork->newEntities(), $this->unitOfWork->managedEntities())
            : $this->unitOfWork->removedEntities();

        if ($queue === []) {
            return;
        }

        $visited = [];
        foreach ($queue as $seed) {
            $visited[spl_object_id($seed)] = true;
        }

        while ($queue !== []) {
            $current = array_shift($queue);
            $metadata = $this->metadata->for($current::class);

            foreach ($metadata->associations() as $assoc) {
                $applies = match ($operation) {
                    Cascade::PERSIST => $assoc->cascadesPersist(),
                    Cascade::REMOVE  => $assoc->cascadesRemove(),
                    default          => false,
                };

                if (! $applies) {
                    continue;
                }

                $targets = $this->readAssociationTargets($current, $assoc);
                foreach ($targets as $target) {
                    $targetOid = spl_object_id($target);
                    if (isset($visited[$targetOid])) {
                        continue;
                    }

                    $visited[$targetOid] = true;

                    if ($operation === Cascade::PERSIST) {
                        if ($this->unitOfWork->state($target) === EntityState::Detached) {
                            $this->persist($target);
                        }
                    } else {
                        $state = $this->unitOfWork->state($target);
                        if ($state === EntityState::Managed || $state === EntityState::New) {
                            $this->remove($target);
                        }
                    }

                    $queue[] = $target;
                }
            }
        }
    }

    /**
     * Scan every managed entity whose metadata declares a OneToMany inverse
     * association with orphanRemoval=true, and mark as Removed any collection
     * member that was present in the original snapshot but is no longer in the
     * current collection.
     */
    private function collectOrphansForRemoval(): void
    {
        foreach ($this->unitOfWork->managedEntities() as $entity) {
            $metadata = $this->unitOfWork->metadataFor($entity);

            foreach ($this->orphanRemovalAssociationsToScan($entity, $metadata) as $assoc) {
                $diff = $this->unitOfWork->collectionDiff($entity, $assoc->name);
                foreach ($diff['removed'] as $orphan) {
                    // V1 constraint: orphanRemoval operates only on Managed
                    // post-flush entities. New (not yet persisted) items are
                    // deliberately skipped to keep behaviour deterministic.
                    if ($this->unitOfWork->state($orphan) !== EntityState::Managed) {
                        continue;
                    }

                    $this->unitOfWork->remove($orphan);
                }
            }
        }
    }

    /**
     * @return list<object>
     */
    private function loadOneToManyCollection(EntityAssociationMetadata $association, int|string $identifier): array
    {
        $targetMetadata = $this->metadata->for($association->targetEntity);
        $rows = $this->queries->table($targetMetadata->table)
            ->where($association->targetColumn, $identifier)
            ->get()
            ->rows();

        $results = [];
        foreach ($rows as $row) {
            $results[] = $this->hydrateManaged($targetMetadata, $row);
        }

        return $results;
    }

    /**
     * @return list<object>
     */
    private function loadManyToManyCollection(EntityAssociationMetadata $association, int|string $identifier): array
    {
        if (! $association->usesJoinTable()) {
            throw new RuntimeException(sprintf(
                'ManyToMany association [%s] is missing JoinTable metadata.',
                $association->name,
            ));
        }

        $membershipRows = $this->queries->table($association->joinTable)
            ->where($association->joinTableSourceColumn, $identifier)
            ->get()
            ->rows();

        $targetMetadata = $this->metadata->for($association->targetEntity);
        $results = [];
        foreach ($membershipRows as $row) {
            $targetId = $row[$association->joinTableTargetColumn] ?? null;
            if ($targetId === null || $targetId === '') {
                continue;
            }

            $target = $this->find($association->targetEntity, $targetId);
            if ($target !== null) {
                $results[] = $target;
            }
        }

        return $results;
    }

    /**
     * Read a single association's target entities regardless of whether it is
     * a MANY_TO_ONE (single object) or ONE_TO_MANY (iterable).
     *
     * @return list<object>
     */
    private function readAssociationTargets(object $entity, EntityAssociationMetadata $assoc): array
    {
        $property = $assoc->property;
        $property->setAccessible(true);
        $raw = $property->getValue($entity);

        if ($raw === null) {
            return [];
        }

        if (is_object($raw) && ! $raw instanceof \Traversable) {
            return [$raw];
        }

        if (is_array($raw)) {
            return array_values(array_filter($raw, 'is_object'));
        }

        if ($raw instanceof \Traversable) {
            $out = [];
            foreach ($raw as $item) {
                if (is_object($item)) {
                    $out[] = $item;
                }
            }

            return $out;
        }

        return [];
    }

    /**
     * Insert NEW entities in topological order (dependencies first). ManyToOne
     * owning-sides require the referenced (target) entity to have an id before
     * the child INSERT is executed.
     *
     * Algorithm: repeat passes over remaining NEW entities until the queue is
     * empty. In each pass only entities whose ManyToOne target identifiers
     * are already available (assigned or managed-with-id) are inserted. If a
     * full pass produces no forward progress and entities remain → circular.
     */
    private function flushNewEntitiesInDependencyOrder(): void
    {
        $remaining = $this->unitOfWork->newEntities();
        $stallGuard = 0;

        while ($remaining !== []) {
            $stallGuard++;
            $progress = false;
            $next = [];

            foreach ($remaining as $candidate) {
                if ($this->newEntityIsInsertable($candidate)) {
                    $this->flushInsert($candidate);
                    $progress = true;

                    continue;
                }

                $next[] = $candidate;
            }

            if (! $progress) {
                throw new RuntimeException(sprintf(
                    'Unable to resolve NEW entity insert order for [%s] classes. Circular MANY_TO_ONE reference? Remaining: %s.',
                    count($next),
                    implode(', ', array_map(static fn(object $o): string => $o::class, array_slice($next, 0, 5))),
                ));
            }

            $remaining = $next;
        }
    }

    /**
     * Can a NEW entity be safely INSERTed right now? Answer is YES when every
     * ManyToOne owning-side association either:
     *   - has no target object (nullable FK), or
     *   - target object already has a non-null identifier (already inserted
     *     or id assigned by the user ahead of persist).
     */
    private function newEntityIsInsertable(object $entity): bool
    {
        $metadata = $this->unitOfWork->metadataFor($entity);

        foreach ($metadata->associations() as $assoc) {
            if (! ($assoc->isManyToOne() || ($assoc->isOneToOne() && $assoc->isOwningSide()))) {
                continue;
            }

            $property = $assoc->property;
            $property->setAccessible(true);
            $target = $property->getValue($entity);

            if ($target === null) {
                continue;
            }

            if (! is_object($target)) {
                continue;
            }

            $targetMeta = $this->metadata->for($target::class);
            $targetId = $targetMeta->identifierValue($target);
            if ($targetId === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Before writing an entity via INSERT/UPDATE, make sure every ManyToOne
     * owning side FK field (e.g. `postId` property) is in sync with the
     * actual identifier value of the associated object held in the PHP
     * reference (e.g. `$comment->post->id`).
     *
     * Uses the same source-field naming convention as associationSourceValue.
     */
    private function populateManyToOneForeignKeys(object $entity, EntityMetadata $metadata): void
    {
        foreach ($metadata->associations() as $assoc) {
            if (! ($assoc->isManyToOne() || ($assoc->isOneToOne() && $assoc->isOwningSide()))) {
                continue;
            }

            $target = $this->readSingleAssociationTarget($assoc, $entity);
            if ($target === null) {
                continue;
            }

            $targetMeta = $this->metadata->for($target::class);
            $targetId = $targetMeta->identifierValue($target);
            if ($targetId === null) {
                continue;
            }

            $this->assignOwningSideForeignKey($entity, $metadata, $assoc, $targetId);
        }
    }

    private function readSingleAssociationTarget(EntityAssociationMetadata $assoc, object $entity): ?object
    {
        $property = $assoc->property;
        $property->setAccessible(true);
        $raw = $property->getValue($entity);

        return is_object($raw) ? $raw : null;
    }

    /**
     * @param int|string $value
     */
    private function assignOwningSideForeignKey(
        object $entity,
        EntityMetadata $metadata,
        EntityAssociationMetadata $assoc,
        mixed $value,
    ): void {
        // Naming priority (mirrors associationSourceValue helper):
        //   1. explicit {sourceField}Id field declared on the entity
        //   2. field whose column matches $assoc->sourceColumn
        //   3. explicit $assoc->sourceField name directly (if exists as field)
        if ($assoc->sourceField !== null && $metadata->hasField($assoc->sourceField . 'Id')) {
            $metadata->field($assoc->sourceField . 'Id')->setValue($entity, $value);

            return;
        }

        if ($assoc->sourceColumn !== null) {
            foreach ($metadata->mappedFields() as $field) {
                if ($field->column === $assoc->sourceColumn) {
                    $field->setValue($entity, $value);

                    return;
                }
            }
        }

        if ($assoc->sourceField !== null && $metadata->hasField($assoc->sourceField)) {
            $metadata->field($assoc->sourceField)->setValue($entity, $value);
        }
    }

    private function flushManyToManyMembershipChanges(): void
    {
        foreach ($this->unitOfWork->managedEntities() as $entity) {
            $metadata = $this->unitOfWork->metadataFor($entity);
            $key = $this->unitOfWork->keyFor($entity);
            if ($key === null) {
                continue;
            }

            foreach ($this->manyToManyAssociationsToSynchronize($entity, $metadata) as $association) {
                $currentIds = $this->currentManyToManyTargetIds($entity, $association, requireIdentifiers: true);
                $existingIds = $this->existingManyToManyTargetIds($association, $key->identifier);

                foreach (array_diff($existingIds, $currentIds) as $removedId) {
                    $this->queries->table($association->joinTable)
                        ->where($association->joinTableSourceColumn, $key->identifier)
                        ->where($association->joinTableTargetColumn, $removedId)
                        ->delete();
                }

                foreach (array_diff($currentIds, $existingIds) as $addedId) {
                    $this->queries->table($association->joinTable)->insert([
                        $association->joinTableSourceColumn => $key->identifier,
                        $association->joinTableTargetColumn => $addedId,
                    ]);
                }
            }

            $this->unitOfWork->snapshotOneToManyCollections($entity, $metadata);
        }
    }

    private function refreshPartialManagedCollectionSnapshots(): void
    {
        foreach ($this->unitOfWork->managedEntities() as $entity) {
            if (! $this->unitOfWork->isPartialManaged($entity)) {
                continue;
            }

            $metadata = $this->unitOfWork->metadataFor($entity);
            foreach ($this->unitOfWork->loadedPartialManagedToManyAssociations($entity) as $associationName) {
                $diff = $this->unitOfWork->collectionDiff($entity, $associationName);
                if ($diff['removed'] === [] && $diff['added'] === []) {
                    continue;
                }

                $this->unitOfWork->snapshotOneToManyCollections($entity, $metadata);

                break;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function trackedCurrentValues(object $entity, EntityMetadata $metadata): array
    {
        $current = $metadata->extract($entity, includeIdentifier: false);

        if (! $this->unitOfWork->isPartialManaged($entity)) {
            return $current;
        }

        $allowed = array_fill_keys($this->unitOfWork->partialManagedFields($entity), true);
        unset($allowed[$metadata->identifier->name]);

        return array_intersect_key($current, $allowed);
    }

    private function synchronizeTrackingState(object $entity, EntityKey $key): void
    {
        if ($this->unitOfWork->isPartialManaged($entity)) {
            $this->unitOfWork->synchronizePartial($entity, $key, $this->unitOfWork->partialManagedFields($entity));

            return;
        }

        $this->unitOfWork->synchronize($entity, $key);
    }

    /**
     * @param list<string> $loadedFields
     * @return list<string>
     */
    private function partialManagedRootFieldNames(EntityMetadata $metadata, array $loadedFields): array
    {
        return array_values(array_filter(
            $loadedFields,
            static function (string $field) use ($metadata): bool {
                if ($metadata->hasField($field)) {
                    return true;
                }

                if (! str_contains($field, '.')) {
                    return false;
                }

                [$embeddedName, $innerName] = explode('.', $field, 2);
                return $metadata->hasEmbedded($embeddedName)
                    && $metadata->embedded($embeddedName)->hasInnerField($innerName);
            },
        ));
    }

    /**
     * @return list<int|string>
     */
    private function currentManyToManyTargetIds(
        object $entity,
        EntityAssociationMetadata $association,
        bool $requireIdentifiers = false,
    ): array
    {
        $ids = [];
        foreach ($this->readAssociationTargets($entity, $association) as $target) {
            $targetId = $this->metadata->for($target::class)->identifierValue($target);
            if ($targetId === null) {
                if ($requireIdentifiers) {
                    throw new RuntimeException(sprintf(
                        'Cannot synchronize ManyToMany collection [%s::$%s] because target [%s] has no persistent identifier yet.',
                        $entity::class,
                        $association->name,
                        $target::class,
                    ));
                }

                continue;
            }

            $ids[] = $targetId;
        }

        return array_values(array_unique($ids, SORT_REGULAR));
    }

    /**
     * @return list<int|string>
     */
    private function existingManyToManyTargetIds(EntityAssociationMetadata $association, int|string $sourceId): array
    {
        $rows = $this->queries->table($association->joinTable)
            ->where($association->joinTableSourceColumn, $sourceId)
            ->get()
            ->rows();

        $ids = [];
        foreach ($rows as $row) {
            $targetId = $row[$association->joinTableTargetColumn] ?? null;
            if ($targetId === null || $targetId === '') {
                continue;
            }

            $ids[] = $targetId;
        }

        return array_values(array_unique($ids, SORT_REGULAR));
    }

    /**
     * @return list<EntityAssociationMetadata>
     */
    private function manyToManyAssociationsToSynchronize(object $entity, EntityMetadata $metadata): array
    {
        if (! $this->unitOfWork->isPartialManaged($entity)) {
            return array_values(array_filter(
                $metadata->associations(),
                static fn(EntityAssociationMetadata $association): bool => $association->isManyToMany()
                    && $association->isOwningSide()
                    && $association->usesJoinTable(),
            ));
        }

        $associations = [];
        foreach ($this->unitOfWork->loadedPartialManagedToManyAssociations($entity) as $associationName) {
            $association = $metadata->association($associationName);
            if (! $association->isManyToMany() || ! $association->isOwningSide() || ! $association->usesJoinTable()) {
                continue;
            }

            $associations[] = $association;
        }

        return $associations;
    }

    /**
     * @return list<EntityAssociationMetadata>
     */
    private function orphanRemovalAssociationsToScan(object $entity, EntityMetadata $metadata): array
    {
        if (! $this->unitOfWork->isPartialManaged($entity)) {
            return array_values(array_filter(
                $metadata->associations(),
                static fn(EntityAssociationMetadata $association): bool => $association->isOneToMany()
                    && $association->orphanRemoval,
            ));
        }

        $associations = [];
        foreach ($this->unitOfWork->loadedPartialManagedToManyAssociations($entity) as $associationName) {
            $association = $metadata->association($associationName);
            if (! $association->isOneToMany() || ! $association->orphanRemoval) {
                continue;
            }

            $associations[] = $association;
        }

        return $associations;
    }

    /**
     * @param array{removed: list<object>, added: list<object>} $diff
     */
    private function assertPartialManagedOneToManyMutationIsSynchronizable(
        object $entity,
        EntityAssociationMetadata $association,
        array $diff,
    ): void {
        if ($association->mappedBy === null) {
            return;
        }

        $targetMetadata = $this->metadata->for($association->targetEntity);
        if (! $targetMetadata->hasAssociation($association->mappedBy)) {
            return;
        }

        $owningAssociation = $targetMetadata->association($association->mappedBy);
        $property = $owningAssociation->property;
        $property->setAccessible(true);

        foreach ($diff['added'] as $target) {
            if ($property->getValue($target) !== $entity) {
                throw new RuntimeException(sprintf(
                    'Cannot add target to partially managed collection [%s::$%s] without updating owning side [%s::$%s].',
                    $entity::class,
                    $association->name,
                    $target::class,
                    $owningAssociation->name,
                ));
            }
        }

        if ($association->orphanRemoval) {
            return;
        }

        foreach ($diff['removed'] as $target) {
            if ($property->getValue($target) === $entity) {
                throw new RuntimeException(sprintf(
                    'Cannot remove target from partially managed collection [%s::$%s] while owning side [%s::$%s] still points to the same root.',
                    $entity::class,
                    $association->name,
                    $target::class,
                    $owningAssociation->name,
                ));
            }
        }
    }

    private function deleteManyToManyMembershipsFor(object $entity, EntityMetadata $metadata, int|string $identifier): void
    {
        foreach ($metadata->associations() as $association) {
            if (! $association->isManyToMany() || ! $association->usesJoinTable()) {
                continue;
            }

            $this->queries->table($association->joinTable)
                ->where($association->joinTableSourceColumn, $identifier)
                ->delete();
        }
    }

    /**
     * @param list<object> $entities
     * @return array<string, object>
     */
    private function mapEntitiesByIdentifier(array $entities, EntityMetadata $metadata): array
    {
        $mapped = [];

        foreach ($entities as $entity) {
            $identifier = $metadata->identifierValue($entity);
            if ($identifier === null) {
                continue;
            }

            $mapped[(string) $identifier] = $entity;
        }

        return $mapped;
    }

    /**
     * @param list<int|string> $identifiers
     * @return array<string, object>
     */
    private function fetchEntitiesByIdentifiers(EntityMetadata $metadata, array $identifiers): array
    {
        if ($identifiers === []) {
            return [];
        }

        $rows = $this->queries->table($metadata->table)
            ->whereIn($metadata->identifier->column, array_values($identifiers))
            ->get()
            ->rows();

        $entities = [];
        foreach ($rows as $row) {
            $identifier = $row[$metadata->identifier->column] ?? null;
            if ($identifier === null || $identifier === '') {
                continue;
            }

            $entities[(string) $identifier] = $this->hydrateManaged($metadata, $row);
        }

        return $entities;
    }

    /**
     * @param list<object> $objects
     * @return list<object>
     */
    private function uniqueObjects(array $objects): array
    {
        $unique = [];
        foreach ($objects as $object) {
            $unique[spl_object_id($object)] = $object;
        }

        return array_values($unique);
    }

    private function associationSourceValue(object $entity, EntityAssociationMetadata $association): int|string|null
    {
        $metadata = $this->metadata->for($entity::class);

        if ($association->sourceField === null || ! $metadata->hasField($association->sourceField . 'Id')) {
            $candidate = null;
            foreach ($metadata->mappedFields() as $field) {
                if ($association->sourceColumn !== null && $field->column === $association->sourceColumn) {
                    $candidate = $field;
                    break;
                }
            }

            if ($candidate === null && $association->sourceField !== null && $metadata->hasField($association->sourceField)) {
                $candidate = $metadata->field($association->sourceField);
            }

            if ($candidate === null) {
                return null;
            }

            $value = $candidate->hasValue($entity) ? $candidate->getValue($entity) : null;

            if ($value === null || $value === '') {
                return null;
            }

            return is_int($value) || is_string($value) ? $value : (string) $value;
        }

        $sourceField = $metadata->field($association->sourceField . 'Id');
        $value = $sourceField->hasValue($entity) ? $sourceField->getValue($entity) : null;

        if ($value === null || $value === '') {
            return null;
        }

        return is_int($value) || is_string($value) ? $value : (string) $value;
    }

    private function assignAssociationValue(object $entity, EntityAssociationMetadata $association, mixed $value): void
    {
        $property = $association->property;
        if (method_exists($property, 'setAccessible')) {
            $property->setAccessible(true);
        }
        $property->setValue($entity, $value);
    }
}
