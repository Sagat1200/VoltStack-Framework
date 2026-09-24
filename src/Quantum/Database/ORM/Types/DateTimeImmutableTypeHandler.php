<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Types;

use DateTimeImmutable;
use DateTimeInterface;
use Quantum\Database\ORM\Metadata\EntityFieldMetadata;
use Quantum\Database\ORM\Types\Contracts\TypeHandlerInterface;
use RuntimeException;

final readonly class DateTimeImmutableTypeHandler implements TypeHandlerInterface
{
    public function id(): string
    {
        return 'datetime_immutable';
    }

    public function toPhp(mixed $value, EntityFieldMetadata $field): mixed
    {
        if ($value === null || $value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw new RuntimeException(sprintf(
                'Field [%s] expects a DateTimeImmutable-compatible value, got [%s].',
                $field->name,
                get_debug_type($value),
            ));
        }

        return new DateTimeImmutable((string) $value);
    }

    public function toDatabase(mixed $value, EntityFieldMetadata $field): mixed
    {
        if ($value === null) {
            return null;
        }

        $date = $this->toPhp($value, $field);

        if (! $date instanceof DateTimeImmutable) {
            throw new RuntimeException(sprintf(
                'Field [%s] expects a DateTimeImmutable value, got [%s].',
                $field->name,
                get_debug_type($date),
            ));
        }

        return $date->format(DateTimeInterface::ATOM);
    }
}
