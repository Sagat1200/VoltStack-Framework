<?php declare(strict_types=1);

namespace Quantum\Database\Orm\Hydration;

use Quantum\Database\Dbal\Value\QueryResult;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Orm\UnitOfWork\IdentityMapInterface;

interface HydratorInterface
{
    /**
     * @return iterable<object> entidades hydrated (array si indexById = true => [idHash => entity]).
     */
    public function hydrateAll(
        QueryResult $qr,
        CompiledEntityMetadata $meta,
        IdentityMapInterface $identityMap,
        HydrationOptions $opts,
    ): iterable;

    /** @return object|null primer row hydrated o null. */
    public function hydrateOne(
        QueryResult $qr,
        CompiledEntityMetadata $meta,
        IdentityMapInterface $identityMap,
        HydrationOptions $opts,
    ): ?object;

    /**
     * Hydrate desde un array raw rows (útil para tests sin BD).
     *
     * @param list<array<string,mixed>> $rows
     * @return iterable<object>
     */
    public function hydrateAllFromRows(
        array $rows,
        CompiledEntityMetadata $meta,
        IdentityMapInterface $identityMap,
        HydrationOptions $opts,
    ): iterable;
}
