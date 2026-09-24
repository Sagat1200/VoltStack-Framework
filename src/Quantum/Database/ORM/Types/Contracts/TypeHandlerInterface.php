<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Types\Contracts;

use Quantum\Database\ORM\Metadata\EntityFieldMetadata;

interface TypeHandlerInterface
{
    public function id(): string;

    public function toPhp(mixed $value, EntityFieldMetadata $field): mixed;

    public function toDatabase(mixed $value, EntityFieldMetadata $field): mixed;
}
