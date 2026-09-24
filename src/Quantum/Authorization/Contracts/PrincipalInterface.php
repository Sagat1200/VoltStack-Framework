<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Authorization\Principal\PrincipalType;

interface PrincipalInterface
{
    public function id(): string;

    public function type(): PrincipalType;

    public function authenticated(): bool;

    /**
     * @return array<string, mixed>
     */
    public function claims(): array;
}
