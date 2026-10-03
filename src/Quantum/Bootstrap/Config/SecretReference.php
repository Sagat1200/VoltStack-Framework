<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Config;

final readonly class SecretReference
{
    public function __construct(
        private string $key,
        private string $provider = 'env',
        private bool $required = true,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function required(): bool
    {
        return $this->required;
    }

    /**
     * @return array{type: string, key: string, provider: string, required: bool}
     */
    public function toArray(): array
    {
        return [
            'type' => 'secret',
            'key' => $this->key,
            'provider' => $this->provider,
            'required' => $this->required,
        ];
    }
}
