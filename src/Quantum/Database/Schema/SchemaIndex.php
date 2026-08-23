<?php

declare(strict_types=1);

namespace Quantum\Database\Schema;

final readonly class SchemaIndex
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public string $name,
        public array $columns,
        public bool $unique = false,
        public bool $primary = false,
    ) {}

    /**
     * @return array{name:string,columns:list<string>,unique:bool,primary:bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'unique' => $this->unique,
            'primary' => $this->primary,
        ];
    }
}