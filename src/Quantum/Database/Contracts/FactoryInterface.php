<?php

declare(strict_types=1);

namespace Quantum\Database\Contracts;

/**
 * Contract for an entity factory able to generate in-memory objects
 * and optionally persist them through the ORM EntityManager.
 *
 * Factories are typically file-discovered from `database/factories` and
 * registered against their target entity FQCN by the factory registry.
 */
interface FactoryInterface
{
    /**
     * Return the default attribute set for a single entity instance.
     *
     * The return is a raw associative array of property-name => value.
     * Embedded value objects and typed fields (enums, DateTimeImmutable,
     * JSON payloads) are produced using their domain representation, not
     * raw database values: the EntityManager will convert them via the
     * existing TypeRegistry pipeline on flush.
     *
     * @return array<string, mixed>
     */
    public function definition(): array;

    /**
     * FQCN of the entity this factory builds.
     *
     * @return class-string
     */
    public function entityClass(): string;

    /**
     * Produce N copies of this factory when calling `make()` / `create()`.
     *
     * Implementations MUST be immutable and return a new instance with the
     * requested count so chained invocations (`factory()->times(5)->create()`)
     * do not mutate shared state.
     */
    public function times(int $count): static;

    /**
     * Build one or more in-memory entity instances WITHOUT persisting them.
     *
     * When a count has been set via `times()` the return is a numeric
     * `list<object>`; otherwise it is a single entity `object`.
     *
     * @param array<string, mixed> $overrides property-name => value to merge
     *                                        on top of `definition()` output.
     *
     * @return object|list<object>
     */
    public function make(array $overrides = []): object|array;

    /**
     * Build one or more entity instances AND persist them through the
     * EntityManager (no implicit flush is performed — callers are expected
     * to `EntityManager::flush()` afterwards or rely on SeederRunner doing
     * so at the end of each seeder batch).
     *
     * Mirrors `make()` shape: when `times(N)` is active returns `list<object>`;
     * otherwise a single `object`.
     *
     * @param array<string, mixed> $overrides property-name => value to merge.
     *
     * @return object|list<object>
     */
    public function create(array $overrides = []): object|array;
}
