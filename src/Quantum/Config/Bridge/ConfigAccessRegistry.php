<?php

declare(strict_types=1);

namespace Quantum\Config\Bridge;

final class ConfigAccessRegistry
{
    /**
     * @var array<string, ConfigAccessRule>
     */
    private array $rules = [];

    public function register(ConfigAccessRule $rule): void
    {
        $this->rules[$rule->path()] = $rule;
    }

    public function static(string $path, ?string $consumer = null): void
    {
        $this->register(new ConfigAccessRule($path, ConfigAccessMode::Static, $consumer));
    }

    public function scoped(string $path, ?string $consumer = null, ?string $scope = 'request'): void
    {
        $this->register(new ConfigAccessRule($path, ConfigAccessMode::Scoped, $consumer, $scope));
    }

    public function has(string $path): bool
    {
        return isset($this->rules[$path]);
    }

    public function modeFor(string $path): ConfigAccessMode
    {
        return $this->rules[$path]?->mode() ?? ConfigAccessMode::Static;
    }

    public function ruleFor(string $path): ?ConfigAccessRule
    {
        return $this->rules[$path] ?? null;
    }

    /**
     * @return array<string, ConfigAccessRule>
     */
    public function all(): array
    {
        return $this->rules;
    }
}
