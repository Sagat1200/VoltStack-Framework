<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Orm;

use Quantum\Database\Operation\DatabaseOperationInterface;
use Quantum\Database\Operation\OperationKind;

final readonly class EntityDeleteOperation implements DatabaseOperationInterface
{
    /**
     * @param class-string $entityClass
     * @param array<string,mixed> $identifier pk
     */
    public function __construct(
        public string $entityClass,
        public string $tableName,
        public array $identifier,
        public int|string|null $expectedVersion = null,
        public string $versionColumn = 'version',
    ) {}

    public function kind(): OperationKind { return OperationKind::OrmDelete; }

    public function describe(): string
    {
        return sprintf('[orm_delete] %s -> %s', $this->entityClass, $this->tableName);
    }
}
