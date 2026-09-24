<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Types;

use Quantum\Database\ORM\Metadata\EntityFieldMetadata;
use Quantum\Database\ORM\Types\Contracts\TypeHandlerInterface;

final readonly class ScalarTypeHandler implements TypeHandlerInterface
{
    public function __construct(
        private string $type,
    ) {
    }

    public function id(): string
    {
        return $this->type;
    }

    public function toPhp(mixed $value, EntityFieldMetadata $field): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
            'string' => (string) $value,
            default => $value,
        };
    }

    public function toDatabase(mixed $value, EntityFieldMetadata $field): mixed
    {
        return $this->toPhp($value, $field);
    }
}
