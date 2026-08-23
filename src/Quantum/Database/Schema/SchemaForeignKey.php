<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

final readonly class SchemaForeignKey
{
    /**
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public string $referencedTable,
        public array $referencedColumns,
        public ?string $referencedSchema = null,
        public ?string $onUpdate = null,
        public ?string $onDelete = null,
    ) {
    }

    /**
     * @return array{name:string,columns:list<string>,referenced_table:string,referenced_columns:list<string>,referenced_schema:?string,on_update:?string,on_delete:?string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'referenced_table' => $this->referencedTable,
            'referenced_columns' => $this->referencedColumns,
            'referenced_schema' => $this->referencedSchema,
            'on_update' => $this->onUpdate,
            'on_delete' => $this->onDelete,
        ];
    }
}
