<?php

declare(strict_types=1);

namespace Quantum\Database\Schema\Model;

final readonly class DropTableDefinition
{
    public function __construct(
        public string $table,
        public bool $ifExists = true,
    ) {
    }
}
