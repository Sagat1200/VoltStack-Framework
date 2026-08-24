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
            if ($table->name === $name || $table->qualifiedName() === $name) {
                return $table;
            }
        }

        return null;
    }

    /**
     * @param list<string> $tableNames
     */
    public function withoutTables(array $tableNames): self
    {
        if ($tableNames === []) {
            return $this;
        }

        $lookup = [];
        foreach ($tableNames as $tableName) {
            $lookup[strtolower($tableName)] = true;
        }

        return new self(
            $this->driver,
            array_values(array_filter(
                $this->tables,
                static fn(SchemaTable $table): bool => !isset($lookup[strtolower($table->name)])
                    && !isset($lookup[strtolower($table->qualifiedName())]),
            )),
        );
    }

    /**
     * @return array{driver:string,tables:list<array<string,mixed>>}
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'tables' => array_map(static fn(SchemaTable $table): array => $table->toArray(), $this->tables),
        ];
    }
}
