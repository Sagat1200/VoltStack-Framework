<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Orm;

use Quantum\Database\Operation\DatabaseOperationInterface;
use Quantum\Database\Operation\OperationKind;

/**
 * Operación ORM UPDATE. changeSet solo contiene propiedades modificadas.
 */
final readonly class EntityUpdateOperation implements DatabaseOperationInterface
{
    /**
     * @param class-string $entityClass
     * @param array<string,mixed> $identifier pk column => value (puede ser compuesto)
     * @param array<string,mixed> $changeSet column modified => new value
     * @param int|string|null $expectedVersion optimistic lock
     */
    public function __construct(
        public string $entityClass,
        public string $tableName,
        public array $identifier,
        public array $changeSet,
        public string $versionColumn = 'version',
        public int|string|null $expectedVersion = null,
    ) {}

    public function kind(): OperationKind { return OperationKind::OrmUpdate; }

    public function describe(): string
    {
        return sprintf('[orm_update] %s -> %s (%d changes)', $this->entityClass, $this->tableName, count($this->changeSet));
    }
}
