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
