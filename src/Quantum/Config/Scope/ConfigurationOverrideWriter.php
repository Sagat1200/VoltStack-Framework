<?php

declare(strict_types=1);

namespace Quantum\Config\Scope;

final class ConfigurationOverrideWriter
{
    public function __construct(
        private readonly ConfigurationScopeRegistry $registry,
    ) {
    }

    public function set(string $path, mixed $value): void
    {
        $this->registry->currentOrFail()->setOverride($path, $value);
    }

    public function forget(string $path): void
    {
        $this->registry->currentOrFail()->forgetOverride($path);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function replace(array $overrides): void
    {
        $this->registry->currentOrFail()->replaceOverrides($overrides);
    }

    public function clear(): void
    {
        $this->registry->currentOrFail()->clearOverrides();
    }

    /**
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        return $this->registry->currentOrFail()->overrides();
    }
}
