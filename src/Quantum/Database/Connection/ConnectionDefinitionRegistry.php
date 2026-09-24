<?php

declare(strict_types=1);

namespace Quantum\Database\Connection;

use Quantum\Database\Config\DatabaseConfiguration;

final class ConnectionDefinitionRegistry
{
    /**
     * @var array<string, ConnectionDefinition>
     */
    private array $definitions = [];

    public function __construct(
        private readonly DatabaseConfiguration $configuration,
    ) {
        foreach ($configuration->connections as $name => $definition) {
            $compiled = $this->compileDefinition($name, $definition);

            if ($compiled !== null) {
                $this->definitions[$name] = $compiled;
            }
        }
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    public function defaultConnectionName(): string
    {
        return $this->configuration->defaultConnectionName;
    }

    public function find(?string $name = null): ?ConnectionDefinition
    {
        $target = $name ?? $this->defaultConnectionName();

        return $this->definitions[$target] ?? null;
    }

    /**
     * @return array<string, ConnectionDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function compileDefinition(string $name, array $definition): ?ConnectionDefinition
    {
        $driver = $this->normalizeString($definition['driver'] ?? null, 'sqlite');
        $database = $this->normalizeString($definition['database'] ?? null, '');

        if ($database === '') {
            return null;
        }

        $host = $this->normalizeString($definition['host'] ?? null);
        $port = isset($definition['port']) && is_numeric($definition['port']) ? (int) $definition['port'] : null;
        $username = $this->normalizeString($definition['username'] ?? null);
        $password = is_string($definition['password'] ?? null) ? $definition['password'] : null;
        $charset = $this->normalizeString($definition['charset'] ?? null);
        $prefix = $this->normalizeString($definition['prefix'] ?? null, '');
        $platform = $this->normalizeString($definition['platform'] ?? null);
        $dialect = $this->normalizeString($definition['dialect'] ?? null);
        $options = is_array($definition['options'] ?? null) ? $definition['options'] : [];

        return new ConnectionDefinition(
            name: $name,
            driver: $driver,
            database: $database,
            host: $host,
            port: $port,
            username: $username,
            password: $password,
            charset: $charset,
            prefix: $prefix,
            platform: $platform,
            dialect: $dialect,
            options: $options,
        );
    }

    private function normalizeString(mixed $value, ?string $default = null): ?string
    {
        if (! is_string($value)) {
            return $default;
        }

        $value = trim($value);

        return $value === '' ? $default : $value;
    }
}
