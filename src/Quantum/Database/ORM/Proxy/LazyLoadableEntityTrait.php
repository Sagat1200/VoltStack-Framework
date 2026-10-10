<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Proxy;

use Quantum\Database\ORM\EntityManager;
use Quantum\Database\ORM\Metadata\EntityAssociationMetadata;
use Quantum\Database\ORM\Metadata\EntityMetadata;
use Quantum\Database\ORM\Proxy\ProxyInitializationStatus;
use RuntimeException;
use WeakReference;

/**
 * Opt-in user-land trait that installs `__get` / `__set` magic interceptors
 * on any entity class to enable real `fetch: lazy` load-on-access behavior.
 *
 * Usage: inside the entity class declaration (works transparently for
 * `final class` entities too):
 *
 *     use Quantum\Database\ORM\Proxy\LazyLoadableEntityTrait;
 *
 *     final class Order
 *     {
 *         use LazyLoadableEntityTrait;
 *
 *         #[Id] public ?int $id = null;
 *         #[ManyToOne(targetEntity: Customer::class, fetch: 'lazy')]
 *         public ?Customer $customer = null;
 *     }
 *
 * Behavior:
 *   - Any access to a mapped association property triggers a single one-shot
 *     materialization via `EntityManager::loadToOne()` / `loadToMany()` when
 *     that property is not yet populated. Subsequent reads bypass this trait
 *     entirely (PHP native `__get` is only called for undefined/setteable
 *     properties; once the real property is populated it shadows the magic).
 *   - Placeholder entities (Uninitialized proxy status returned from
 *     `EntityManager::getReference()`) hydrate-through automatically on the
 *     first mapped-property access before loading the target association.
 *   - Direct scalar/field access on an Uninitialized placeholder is rejected
 *     with the same V1 unified guardrail message used on flush/loaders, so
 *     developers still get an explicit, actionable error instead of silent
 *     nulls.
 *   - Non-mapped (transient) properties bypass every guard and keep normal
 *     PHP semantics. This preserves 100% backward compatibility for entities
 *     that choose to mix mapped fields with arbitrary runtime state.
 *
 * WeakReference coupling:
 *   The containing `EntityManager` injects `$this->__ormEntityManagerWeakRef`
 *   and `$this->__ormEntityClassName` via reflection immediately after the
 *   entity is registered as managed. If the EM is destroyed (scope end,
 *   explicit `unset()`, process GC, or a `new` scope boot after clear that
 *   drops the old reference) subsequent lazy-reads throw a deterministic
 *   "orphaned" RuntimeException. Re-fetch or re-attach to restore the link.
 */
trait LazyLoadableEntityTrait
{
    /** @var WeakReference<EntityManager>|null Back-reference to the managing EntityManager. Weak so it never prevents GC of the EM. */
    private ?WeakReference $__ormEntityManagerWeakRef = null;

    /** @var class-string|null FQCN of the entity. Cached to avoid `static::class` resolution edge-cases inside trait context. */
    private ?string $__ormEntityClassName = null;

    /**
     * Intercepts reads for properties that are not currently populated on
     * the instance. Fast-path return for already-materialized properties
     * keeps overhead at two hashtable lookups for the 99% hot path.
     *
     * @throws RuntimeException on orphaned WeakRef, uninitialized scalar
     *         read, or state corruption detected after hydrate-through.
     */
    public function __get(string $name): mixed
    {
        // 1) FAST PATH: property already materialized on this object. PHP
        //    would normally not even invoke __get for this case, but a custom
        //    `unset()` on a public property can trigger it for an
        //    array_key_exists-negative situation, so guard is defensive.
        if (property_exists($this, $name) && array_key_exists($name, get_object_vars($this))) {
            return $this->$name;
        }

        // 2) Resolve managing EntityManager through the weak reference. A
        //    null here means scope was destroyed / orphaned.
        if (null === $this->__ormEntityManagerWeakRef
            || null === ($em = $this->__ormEntityManagerWeakRef->get())
            || ! $em instanceof EntityManager
        ) {
            throw new RuntimeException(sprintf(
                'Entity [%s] with identifier [%s] used for lazy-loading was detected orphaned; its EntityManager scope has been destroyed, cleared, or re-booted. Re-fetch via EntityManager::find() or use EntityManager::getReference() to obtain a fresh managed placeholder before accessing.',
                $this->__ormEntityClassName ?? static::class,
                (string) ($this->{$this->__ormInferIdProperty()} ?? '<unknown>'),
            ));
        }

        // 3) Detected explicit detached state: WeakRef alive but entity no
        //    longer tracked in UOW (clear / detach called between accesses).
        if (! $em->contains($this)) {
            throw new RuntimeException(sprintf(
                'Entity [%s] with identifier [%s] has been detached (EntityManager::clear() / detach()) and is no longer managed. Re-fetch via EntityManager::find() or re-attach before lazy-accessing.',
                $this->__ormEntityClassName ?? static::class,
                (string) ($this->{$this->__ormInferIdProperty()} ?? '<unknown>'),
            ));
        }

        $className = $this->__ormEntityClassName ?? static::class;
        $metadata = $em->metadata->for($className);
        $status = $em->proxyStatusOf($this);

        // 4) Scalar / embedded mapped field (not an association).
        if ($metadata->hasField($name)) {
            if ($status === ProxyInitializationStatus::Uninitialized) {
                $this->__ormThrowUninitializedScalarAccess($em, $metadata, $name);
            }

            // Initialized but still not present as object var -> ORM state
            // corruption: hydrate() should always leave every scalar field
            // populated. Hard fail instead of returning misleading null.
            throw new RuntimeException(sprintf(
                'ORM state corruption: entity [%s] is marked Initialized but scalar/embedded field [%s::$%s] is not materialized on the instance. Re-hydrate via EntityManager::refresh($entity).',
                $metadata->className,
                $metadata->className,
                $name,
            ));
        }

        // 5) Association case (lazy = default). Two sub-flows.
        if (isset($metadata->associations[$name])) {
            $association = $metadata->associations[$name];

            // 5a) Placeholder hydrate-through. First access to any mapped
            //     property on a getReference() placeholder boots the real
            //     row, then continues below to resolve the association.
            if ($status === ProxyInitializationStatus::Uninitialized) {
                $em->initializeProxy($this);
                // After initializeProxy the property may have been loaded
                // eagerly via the configured preload; re-check fast-path.
                if (array_key_exists($name, get_object_vars($this))
                    && $this->$name !== null
                    && ($association->isToMany() ? $this->$name !== [] : true)
                ) {
                    return $this->$name;
                }
            }

            // 5b) Re-check fast-path one more time (covers eager-preload
            //     during initializeProxy and also any user-side value set
            //     between the top of the method and here).
            if (array_key_exists($name, get_object_vars($this))) {
                $current = $this->$name;
                $materialized = $current !== null
                    && ($association->isToOne() || $current !== []);
                if ($materialized) {
                    return $current;
                }
            }

            // 5c) One-shot real loader. `loadToOne()` / `loadToMany()`
            //     internally call assignAssociationValue() which reflection-
            //     writes the target property back on the entity so
            //     subsequent accesses skip this trait entirely (PHP native
            //     shadowing = idempotent, zero follow-up cost).
            return $association->isToOne()
                ? $em->loadToOne($this, $name)
                : $em->loadToMany($this, $name);
        }

        // 6) Unknown property name (not a field, not an association, not
        //    physically set). Preserve vanilla PHP notice semantics so
        //    typos and misspellings surface the same way as in plain POPO.
        trigger_error(
            sprintf(
                'Undefined property: %s::$%s',
                $className,
                $name,
            ),
            E_USER_NOTICE,
        );

        return null;
    }

    /**
     * Intercepts writes to enforce a minimum safety net: writing a scalar
     * (persisted) field onto an Uninitialized placeholder is prohibited
     * (the entity has no snapshot yet; a later flush would compare against
     * a synthetic baseline or silently lose the user write). Associations
     * and transient (non-mapped) writes are allowed always so developers
     * can pre-wire object graphs before calling initializeProxy/refresh.
     *
     * Mapped associations written explicitly by user code WIN over lazy
     * materialization: once the property is populated the fast-path skips
     * the loader as expected.
     *
     * @throws RuntimeException when the entity is an Uninitialized proxy
     *         and the target property is a persisted (non-association) field.
     */
    public function __set(string $name, mixed $value): void
    {
        $em = null;
        $metadata = null;

        // Only run guardrails IF the EM link is alive. If orphaned, let the
        // write go through; reads will later throw a deterministic orphaned
        // message instead of masking the problem with a set-time failure.
        if (null !== $this->__ormEntityManagerWeakRef
            && null !== ($em = $this->__ormEntityManagerWeakRef->get())
            && $em instanceof EntityManager
            && $em->contains($this)
        ) {
            $className = $this->__ormEntityClassName ?? static::class;
            $metadata = $em->metadata->for($className);
            $status = $em->proxyStatusOf($this);

            if ($status === ProxyInitializationStatus::Uninitialized
                && $metadata->hasField($name)
            ) {
                $identifier = (string) ($this->{$this->__ormInferIdProperty()} ?? '<unknown>');
                throw new RuntimeException(sprintf(
                    'Entity [%s] with identifier [%s] is an uninitialized proxy placeholder. Cannot overwrite persisted field [%s::$%s] before hydration. Call EntityManager::initializeProxy($entity) or EntityManager::refresh($entity) first.',
                    $metadata->className,
                    $identifier,
                    $metadata->className,
                    $name,
                ));
            }
        }

        $this->$name = $value;
    }

    /**
     * Helper: unified V1 exact-message reproduction for uninitialized scalar
     * access. Keeps DX consistent between `assertNoUninitializedProxyAccess`
     * (EntityManager private helper used on flush / loaders / preload) and
     * the trait magic-access guard. Uses the same wording so error search /
     * documentation / grep across the codebase lands on every call site.
     */
    private function __ormThrowUninitializedScalarAccess(
        EntityManager $em,
        EntityMetadata $metadata,
        string $operation,
    ): never {
        $identifierRaw = method_exists($em, 'requireKey')
            ? (static function () use ($em, $metadata, $operation) {
                // Access the key-for helper via reflection on the metadata:
                try {
                    $idProp = $metadata->identifier;
                    $val = $idProp->property->getValue($this);
                    return $val ?? '<unknown>';
                } catch (\Throwable) {
                    return '<unknown>';
                }
            })()
            : '<unknown>';

        // If we could retrieve via reflection above it was already assigned
        // to local; but try once more for the common path.
        if ($identifierRaw === '<unknown>') {
            try {
                $identifierRaw = $metadata->identifier->property->getValue($this);
            } catch (\Throwable) {
                $identifierRaw = null;
            }
        }

        $identifier = (string) ($identifierRaw ?? '<unknown>');

        throw new RuntimeException(sprintf(
            'Entity [%s] with identifier [%s] is an uninitialized proxy placeholder. Call EntityManager::initializeProxy($entity) or EntityManager::refresh($entity) before accessing its persisted state or triggering operations (lazy scalar %s) that require snapshots.',
            $metadata->className,
            $identifier,
            $operation,
        ));
    }

    /**
     * Best-effort resolve the identifier property name used in exception
     * messages when a metadata lookup cannot be performed. Traits cannot
     * declare abstracts and the entity POPO may or may not expose the id
     * with a single canonical name, so iterate the public-private property
     * list looking for the one used by `EntityMetadata::identifier`.
     */
    private function __ormInferIdProperty(): string
    {
        // Fast guess used by every test fixture: `id` is the overwhelming
        // convention. Avoid reflection in the common happy path.
        if (property_exists($this, 'id')) {
            return 'id';
        }

        // Slower fallback: scan declared non-static properties for any name
        // that is not the two trait-internal markers and has a non-null value
        // that could serve as identifier in the error message. This path is
        // only reached if the user diverged from the `id` convention AND a
        // guardrail is throwing (so once per lifecycle, not on hot path).
        foreach (get_object_vars($this) as $prop => $v) {
            if ($prop === '__ormEntityManagerWeakRef' || $prop === '__ormEntityClassName') {
                continue;
            }
            if ($v !== null) {
                return $prop;
            }
        }

        return 'id';
    }
}
