<?php

declare(strict_types=1);

namespace Quantum\Container\Graph;

use JsonSerializable;

final readonly class ParameterRequirement implements JsonSerializable
{
    public function __construct(
        public string $name,
        public ?string $type,
        public bool $builtin,
        public bool $hasDefault,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'builtin' => $this->builtin,
            'has_default' => $this->hasDefault,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
