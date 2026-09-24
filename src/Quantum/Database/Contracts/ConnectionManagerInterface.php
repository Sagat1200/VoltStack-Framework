<?php

declare(strict_types=1);

namespace Quantum\Database\Contracts;

interface ConnectionManagerInterface
{
    public function connection(?string $name = null): ConnectionInterface;

    public function has(string $name): bool;

    public function defaultConnectionName(): string;

    public function disconnectAll(): void;
}
