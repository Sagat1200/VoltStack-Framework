<?php

declare(strict_types=1);

namespace Quantum\Transport\Runtime;

final readonly class TransportContext
{
    public function __construct(
        public mixed $request = null,
        public array $attributes = [],
    ) {
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self(request: $this->request, attributes: $attributes);
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}

