<?php

declare(strict_types=1);

namespace Quantum\Database\Orm\Association\Collection;

/**
 * CollectionInterface: contrato unificado para colecciones de entidades (ArrayCollection / PersistentCollection).
 *
 * @template T of object
 */
interface CollectionInterface extends \Countable, \IteratorAggregate
{
    /** @param T $element */
    public function add(object $element): bool;

    /** @param int|T $keyOrObject int = offset; object = buscar por identity */
    public function remove(int|object $keyOrObject): ?object;

    /** @param T $element */
    public function contains(object $element): bool;

    public function clear(): void;

    public function isEmpty(): bool;

    /** @return list<T> */
    public function toArray(): array;

    /**
     * @template U
     * @param \Closure(T):U $fn
     * @return self<U>
     */
    public function map(\Closure $fn): self;

    /**
     * @param \Closure(T):bool $fn
     * @return self<T>
     */
    public function filter(\Closure $fn): self;

    /** @return T|null */
    public function first(): ?object;

    /** @return T|null */
    public function last(): ?object;

    /** @return self<T> */
    public function slice(int $offset, ?int $length = null): self;
}