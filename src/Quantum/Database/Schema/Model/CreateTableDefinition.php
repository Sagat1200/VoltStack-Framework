<?php

declare(strict_types=1);

namespace Quantum\Database\Schema\Model;

final readonly class CreateTableDefinition
{
    /**
     * @param list<ColumnDefinition> $columns
     */
    public function __construct(
        public string $table,
        public array $columns,
        public bool $ifNotExists = false,
    ) {
    }
}
