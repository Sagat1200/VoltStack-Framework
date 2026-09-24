<?php

declare(strict_types=1);

namespace Quantum\Database\Schema\Model;

final readonly class ColumnDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable = false,
        public bool $primary = false,
        public bool $autoIncrement = false,
        public bool $unique = false,
        public mixed $default = null,
        public bool $hasDefault = false,
    ) {
    }
}
