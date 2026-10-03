<?php

declare(strict_types=1);

namespace Quantum\Bootstrap\Config;

final readonly class EnvReference
{
    public function __construct(
        private string $name,
        private mixed $default = null,
        private bool $required = false,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function default(): mixed
    {
        return $this->default;
    }

    public function required(): bool
    {
        return $this->required;
    }

    /**
     * @return array{type: string, name: string, default: mixed, required: bool}
     */
    public function toArray(): array
    {
        return [
            'type' => 'env',
            'name' => $this->name,
            'default' => $this->default,
            'required' => $this->required,
        ];
    }
}
