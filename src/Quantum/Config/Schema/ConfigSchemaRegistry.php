<?php

declare(strict_types=1);

namespace Quantum\Config\Schema;

use InvalidArgumentException;

final class ConfigSchemaRegistry
{
    /**
     * @var array<string, ConfigSchema>
     */
    private array $schemas = [];

    public function register(ConfigSchema $schema): void
    {
        $namespace = $schema->namespace();

        if (isset($this->schemas[$namespace])) {
            throw new InvalidArgumentException(sprintf('Configuration schema [%s] is already registered.', $namespace));
        }

        $this->schemas[$namespace] = $schema;
    }

    public function has(string $namespace): bool
    {
        return isset($this->schemas[$namespace]);
    }

    public function get(string $namespace): ConfigSchema
    {
        if (! isset($this->schemas[$namespace])) {
            throw new InvalidArgumentException(sprintf('Configuration schema [%s] is not registered.', $namespace));
        }

        return $this->schemas[$namespace];
    }

    /**
     * @return array<string, ConfigSchema>
     */
    public function all(): array
    {
        return $this->schemas;
    }
}
