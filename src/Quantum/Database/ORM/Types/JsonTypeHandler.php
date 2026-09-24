<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Types;

use JsonException;
use Quantum\Database\ORM\Metadata\EntityFieldMetadata;
use Quantum\Database\ORM\Types\Contracts\TypeHandlerInterface;
use RuntimeException;

final readonly class JsonTypeHandler implements TypeHandlerInterface
{
    public function id(): string
    {
        return 'json';
    }

    public function toPhp(mixed $value, EntityFieldMetadata $field): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            throw new RuntimeException(sprintf(
                'Field [%s] expects a JSON string or array, got [%s].',
                $field->name,
                get_debug_type($value),
            ));
        }

        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf(
                'Field [%s] contains invalid JSON.',
                $field->name,
            ), previous: $exception);
        }
    }

    public function toDatabase(mixed $value, EntityFieldMetadata $field): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf(
                'Field [%s] cannot be encoded as JSON.',
                $field->name,
            ), previous: $exception);
        }
    }
}
