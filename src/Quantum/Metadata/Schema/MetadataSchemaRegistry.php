<?php

declare(strict_types=1);

namespace Quantum\Metadata\Schema;

final class MetadataSchemaRegistry
{
    /**
     * @var array<string, MetadataSchema>
     */
    private array $schemas = [];

    public function register(MetadataSchema $schema): void
    {
        $this->schemas[$schema->key] = $schema;
    }

    public function get(string $key): ?MetadataSchema
    {
        return $this->schemas[$key] ?? null;
    }
}

