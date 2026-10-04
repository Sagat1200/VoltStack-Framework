<?php

declare(strict_types=1);

namespace Quantum\Config\Scope;

use Quantum\Config\ConfigPath;
use Quantum\Config\ConfigSnapshot;
use Quantum\Config\MissingValue;

final class ConfigurationScope
{
    /**
     * @var array<string, mixed>
     */
    private array $overrides = [];

    public function __construct(
        private readonly string $id,
        private readonly string $kind,
        private readonly ?string $parentId,
        private readonly ConfigSnapshot $baseSnapshot,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function parentId(): ?string
    {
        return $this->parentId;
    }

    public function baseSnapshot(): ConfigSnapshot
    {
        return $this->baseSnapshot;
    }

    public function snapshot(): ConfigSnapshot
    {
        return new ConfigSnapshot(
            data: $this->materialize(),
            provenance: $this->baseSnapshot->provenance(),
            schemaHash: $this->baseSnapshot->schemaHash(),
            configId: null,
        );
    }

    public function hasOverride(string|ConfigPath $path): bool
    {
        return $this->overrideValue($path, MissingValue::Token) !== MissingValue::Token;
    }

    public function has(string|ConfigPath $path): bool
    {
        return $this->get($path, MissingValue::Token) !== MissingValue::Token;
    }

    public function get(string|ConfigPath|null $path = null, mixed $default = null): mixed
    {
        if ($path === null) {
            return $this->materialize();
        }

        $override = $this->overrideValue($path, MissingValue::Token);

        if ($override !== MissingValue::Token) {
            return $override;
        }

        return $this->baseSnapshot->get($path, $default);
    }

    public function setOverride(string|ConfigPath $path, mixed $value): void
    {
        $target = &$this->overrides;

        foreach ($this->segments($path) as $segment) {
            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                $target[$segment] = [];
            }

            $target = &$target[$segment];
        }

        $target = $value;
    }

    public function forgetOverride(string|ConfigPath $path): void
    {
        $segments = $this->segments($path);
        $last = array_pop($segments);

        if ($last === null) {
            return;
        }

        $target = &$this->overrides;

        foreach ($segments as $segment) {
            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                return;
            }

            $target = &$target[$segment];
        }

        unset($target[$last]);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function replaceOverrides(array $overrides): void
    {
        $this->overrides = [];

        foreach ($overrides as $path => $value) {
            if (! is_string($path) || trim($path) === '') {
                continue;
            }

            $this->setOverride($path, $value);
        }
    }

    public function clearOverrides(): void
    {
        $this->overrides = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        return $this->overrides;
    }

    /**
     * @return array<string, mixed>
     */
    public function materialize(): array
    {
        return $this->mergeRecursive($this->baseSnapshot->all(), $this->overrides);
    }

    private function overrideValue(string|ConfigPath $path, mixed $default = null): mixed
    {
        $value = $this->overrides;

        foreach ($this->segments($path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function segments(string|ConfigPath $path): array
    {
        return is_string($path)
            ? ConfigPath::fromString($path)->segments()
            : $path->segments();
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function mergeRecursive(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (isset($base[$key]) && is_array($base[$key]) && is_array($value)) {
                $base[$key] = $this->mergeRecursive($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
