<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Types\Contracts;

use Quantum\Database\ORM\Metadata\EntityTypedFieldInterface;

interface TypeHandlerInterface
{
    public function id(): string;

    public function toPhp(mixed $value, EntityTypedFieldInterface $field): mixed;

    public function toDatabase(mixed $value, EntityTypedFieldInterface $field): mixed;
}
