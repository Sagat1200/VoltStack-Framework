<?php

declare(strict_types=1);

namespace Quantum\Auth\Context;

final readonly class AuthenticationRequest
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public string $requestId,
        public string $transport = 'runtime',
        public array $attributes = [],
    ) {
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
