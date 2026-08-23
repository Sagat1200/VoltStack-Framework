<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

final readonly class SchemaTable
{
    /**
     * @param list<SchemaColumn> $columns
     * @param list<string> $primaryKey
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $primaryKey = [],
        public ?string $createSql = null,
    ) {
    }

    public function column(string $name): ?SchemaColumn
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @return array{name:string,primary_key:list<string>,columns:list<array{name:string,type:string,nullable:bool,default:mixed,primary:bool,auto_increment:bool,ordinal:int}>,create_sql:?string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'primary_key' => $this->primaryKey,
            'columns' => array_map(static fn(SchemaColumn $column): array => $column->toArray(), $this->columns),
            'create_sql' => $this->createSql,
        ];
    }
}
