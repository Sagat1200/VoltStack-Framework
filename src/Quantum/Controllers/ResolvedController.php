<?php

declare(strict_types=1);

namespace Quantum\Controllers;

final class ResolvedController
{
    public function __construct(
        private readonly object $instance,
        private readonly string $method,
    ) {}

    public function instance(): object
    {
        return $this->instance;
    }

    public function method(): string
    {
        return $this->method;
    }
}

