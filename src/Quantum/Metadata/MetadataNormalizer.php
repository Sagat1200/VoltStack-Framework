<?php

declare(strict_types=1);

namespace Quantum\Metadata;

use Quantum\Metadata\Schema\MetadataSchema;

final class MetadataNormalizer
{
    public function normalizeValue(mixed $value, ?MetadataSchema $schema): mixed
    {
        if ($schema === null) {
            return $value;
        }

        return match ($schema->type) {
            MetadataValueType::Array => is_array($value) ? $value : [],
            MetadataValueType::String => is_string($value) ? $value : (is_numeric($value) ? (string) $value : ''),
            MetadataValueType::Int => is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0),
            MetadataValueType::Float => is_float($value) ? $value : (is_numeric($value) ? (float) $value : 0.0),
            MetadataValueType::Bool => is_bool($value) ? $value : (bool) $value,
            default => $value,
        };
    }
}
