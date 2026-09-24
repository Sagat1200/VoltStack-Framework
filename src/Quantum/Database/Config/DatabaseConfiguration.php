<?php

declare(strict_types=1);

namespace Quantum\Database\Config;

final readonly class DatabaseConfiguration
{
    /**
     * @param array<string, array<string, mixed>> $connections
     * @param array<string, mixed> $runtime
     * @param array<string, mixed> $telemetry
     */
    public function __construct(
        public string $defaultConnectionName = 'default',
        public array $connections = [],
        public array $runtime = [],
        public array $telemetry = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function connectionNames(): array
    {
        return array_keys($this->connections);
    }

    public function hasConnection(string $name): bool
    {
        return array_key_exists($name, $this->connections);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function connection(?string $name = null): ?array
    {
        $target = $name ?? $this->defaultConnectionName;

        return $this->connections[$target] ?? null;
    }

    public function runtimeOption(string $key, mixed $default = null): mixed
    {
        return $this->runtime[$key] ?? $default;
    }

    public function telemetryOption(string $key, mixed $default = null): mixed
    {
        return $this->telemetry[$key] ?? $default;
    }
}
