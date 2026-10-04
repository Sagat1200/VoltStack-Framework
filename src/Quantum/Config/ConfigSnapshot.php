<?php

declare(strict_types=1);

namespace Quantum\Config;

final readonly class ConfigSnapshot
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $provenance
     */
    public function __construct(
        private array $data,
        private array $provenance = [],
        private ?string $schemaHash = null,
        private ?string $configId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function provenance(): array
    {
        return $this->provenance;
    }

    public function schemaHash(): ?string
    {
        return $this->schemaHash;
    }

    public function configId(): ?string
    {
        return $this->configId;
    }

    public function has(string|ConfigPath $path): bool
    {
        return $this->get($path, MissingValue::Token) !== MissingValue::Token;
    }

    public function get(string|ConfigPath|null $path = null, mixed $default = null): mixed
    {
        if ($path === null) {
            return $this->all();
        }

        $segments = is_string($path)
            ? ConfigPath::fromString($path)->segments()
            : $path->segments();

        $value = $this->data;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
