<?php

declare(strict_types=1);

namespace Quantum\Database\Schema\Builder;

use Quantum\Database\Schema\Model\CreateTableDefinition;

final class TableBlueprint
{
    /**
     * @var list<ColumnBlueprint>
     */
    private array $columns = [];

    public function __construct(
        private readonly string $table,
        private readonly bool $ifNotExists = false,
    ) {
    }

    public function id(string $name = 'id'): ColumnBlueprint
    {
        return $this->add($name, 'integer')
            ->primary()
            ->autoIncrement();
    }

    public function string(string $name): ColumnBlueprint
    {
        return $this->add($name, 'string');
    }

    public function integer(string $name): ColumnBlueprint
    {
        return $this->add($name, 'integer');
    }

    public function boolean(string $name): ColumnBlueprint
    {
        return $this->add($name, 'boolean');
    }

    public function timestamp(string $name): ColumnBlueprint
    {
        return $this->add($name, 'timestamp');
    }

    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
    }

    public function toCreateDefinition(): CreateTableDefinition
    {
        return new CreateTableDefinition(
            table: $this->table,
            columns: array_map(static fn(ColumnBlueprint $column): \Quantum\Database\Schema\Model\ColumnDefinition => $column->toDefinition(), $this->columns),
            ifNotExists: $this->ifNotExists,
        );
    }

    private function add(string $name, string $type): ColumnBlueprint
    {
        $column = new ColumnBlueprint($name, $type);
        $this->columns[] = $column;

        return $column;
    }
}
