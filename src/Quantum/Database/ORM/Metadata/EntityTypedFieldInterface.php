<?php

declare(strict_types=1);

namespace Quantum\Database\ORM\Metadata;

/**
 * Marker contract for ORM metadata that carries a typed column mapping,
 * usable by the TypeRegistry / TypeHandler pipeline. Implemented by both
 * `EntityFieldMetadata` (scalar fields on an entity) and
 * `EntityEmbeddedFieldMetadata` (nested fields on an embedded value object).
 */
interface EntityTypedFieldInterface
{
    public function name(): string;

    public function column(): string;

    public function type(): ?string;

    /**
     * @return class-string<\BackedEnum>|null
     */
    public function enumClass(): ?string;
}
