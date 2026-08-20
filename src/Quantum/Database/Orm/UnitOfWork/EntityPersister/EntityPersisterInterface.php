<?php

declare(strict_types=1);

namespace Quantum\Database\Orm\UnitOfWork\EntityPersister;

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Orm\Hydration\HydratorInterface;
use Quantum\Database\Orm\Metadata\CompiledEntityMetadata;
use Quantum\Database\Orm\UnitOfWork\ChangeTracking\ChangeSet;
use Quantum\Database\Orm\UnitOfWork\IdentityMapInterface;

interface EntityPersisterInterface
{
    /**
     * @return mixed last insert PK (si generated).
     */
    public function executeInsert(
        object $entity,
        CompiledEntityMetadata $meta,
        ConnectionInterface $conn,
    ): mixed;

    public function executeUpdate(
        object $entity,
        CompiledEntityMetadata $meta,
        ?ChangeSet $cs,
        ConnectionInterface $conn,
    ): void;

    public function executeDelete(
        object $entity,
        CompiledEntityMetadata $meta,
        ConnectionInterface $conn,
    ): void;

    /**
     * @param array<string,mixed> $identifierColumns [colname => value]
     * @return object|null
     */
    public function loadById(
        array $identifierColumns,
        CompiledEntityMetadata $meta,
        ConnectionInterface $conn,
        IdentityMapInterface $im,
        HydratorInterface $hydrator,
        ?string $tenantId = null,
    ): ?object;
}