<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

final readonly class SchemaTable
{
    /**
     * @param list<SchemaColumn> $columns
     * @param list<string> $primaryKey
     * @param list<SchemaIndex> $indexes
     * @param list<SchemaForeignKey> $foreignKeys
     */
    public function __construct(
        public string $name,
        public array $columns,
        public array $primaryKey = [],
        public ?string $createSql = null,
        public ?string $schemaName = null,
        public array $indexes = [],
        public array $foreignKeys = [],
    ) {
    }

    public function qualifiedName(): string
    {
        if ($this->schemaName === null || $this->schemaName === '') {
            return $this->name;
        }

        return $this->schemaName . '.' . $this->name;
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
     * @return array{name:string,schema:?string,qualified_name:string,primary_key:list<string>,columns:list<array{name:string,type:string,portable_type:?string,nullable:bool,default:mixed,primary:bool,auto_increment:bool,ordinal:int,length:?int,precision:?int,scale:?int}>,indexes:list<array{name:string,columns:list<string>,unique:bool,primary:bool}>,foreign_keys:list<array{name:string,columns:list<string>,referenced_table:string,referenced_columns:list<string>,referenced_schema:?string,on_update:?string,on_delete:?string}>,create_sql:?string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'schema' => $this->schemaName,
            'qualified_name' => $this->qualifiedName(),
            'primary_key' => $this->primaryKey,
            'columns' => array_map(static fn(SchemaColumn $column): array => $column->toArray(), $this->columns),
            'indexes' => array_map(static fn(SchemaIndex $index): array => $index->toArray(), $this->indexes),
            'foreign_keys' => array_map(static fn(SchemaForeignKey $foreignKey): array => $foreignKey->toArray(), $this->foreignKeys),
            'create_sql' => $this->createSql,
        ];
    }
}
