<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

final readonly class SchemaSnapshot
{
    /**
     * @param list<SchemaTable> $tables
     */
    public function __construct(
        public string $driver,
        public array $tables,
    ) {}

    public function table(string $name): ?SchemaTable
    {
        foreach ($this->tables as $table) {
            if ($table->name === $name) {
                return $table;
            }
        }

        return null;
    }

    /**
     * @return array{driver:string,tables:list<array{name:string,primary_key:list<string>,columns:list<array{name:string,type:string,nullable:bool,default:mixed,primary:bool,auto_increment:bool,ordinal:int}>,create_sql:?string}>}
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'tables' => array_map(static fn(SchemaTable $table): array => $table->toArray(), $this->tables),
        ];
    }
}