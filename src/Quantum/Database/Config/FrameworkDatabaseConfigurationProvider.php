<?php

declare(strict_types=1);

namespace Quantum\Database\Config;

use Quantum\Config\ConfigRepository;
use Quantum\Database\Contracts\DatabaseConfigurationProviderInterface;

final class FrameworkDatabaseConfigurationProvider implements DatabaseConfigurationProviderInterface
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {
    }

    public function configuration(): DatabaseConfiguration
    {
        $defaultConnectionName = $this->normalizeConnectionName(
            $this->config->get('database.default', 'default'),
            'default',
        );

        $connections = $this->normalizeConnections(
            $this->config->get('database.connections', []),
        );

        $runtime = $this->normalizeMap(
            $this->config->get('database.runtime', []),
        );

        $telemetry = $this->normalizeMap(
            $this->config->get('database.telemetry', []),
        );

        return new DatabaseConfiguration(
            defaultConnectionName: $defaultConnectionName,
            connections: $connections,
            runtime: $runtime,
            telemetry: $telemetry,
        );
    }

    private function normalizeConnectionName(mixed $value, string $default): string
    {
        if (! is_string($value)) {
            return $default;
        }

        $value = trim($value);

        return $value !== '' ? $value : $default;
    }

    /**
     * @param mixed $value
     * @return array<string, array<string, mixed>>
     */
    private function normalizeConnections(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $connections = [];

        foreach ($value as $name => $definition) {
            if (! is_string($name) || trim($name) === '' || ! is_array($definition)) {
                continue;
            }

            $connections[trim($name)] = $definition;
        }

        return $connections;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMap(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
