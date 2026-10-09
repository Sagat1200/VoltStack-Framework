<?php

declare(strict_types=1);

namespace Quantum\Container\Graph;

use JsonSerializable;

final readonly class DependencyReference implements JsonSerializable
{
    public function __construct(
        public string $abstract,
        public bool $optional = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'abstract' => $this->abstract,
            'optional' => $this->optional,
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
