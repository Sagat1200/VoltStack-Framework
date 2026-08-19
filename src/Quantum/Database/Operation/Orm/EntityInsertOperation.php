<?php declare(strict_types=1);

namespace Quantum\Database\Operation\Orm;

use Quantum\Database\Operation\DatabaseOperationInterface;
use Quantum\Database\Operation\OperationKind;

/**
 * Operación de inserción de entidad. V1: lleva los datos de fila ya preparados.
 */
final readonly class EntityInsertOperation implements DatabaseOperationInterface
{
    /** @param class-string $entityClass
     *  @param array<string,mixed> $row column_name => value
     */
    public function __construct(
        public string $entityClass,
        public string $tableName,
        public array $row,
        /** @var list<string>|null $returning lista de columnas (si soportado) */
        public ?array $returning = null,
    ) {}

    public function kind(): OperationKind { return OperationKind::OrmInsert; }

    public function describe(): string
    {
        return sprintf('[orm_insert] %s -> %s (%d cols)', $this->entityClass, $this->tableName, count($this->row));
    }
}
