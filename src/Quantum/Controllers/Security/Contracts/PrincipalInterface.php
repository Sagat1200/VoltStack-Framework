<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Contracts;

use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Controllers\Security\Context\PrincipalType;

interface PrincipalInterface
{
    public function id(): string;

    public function type(): PrincipalType;

    public function authenticated(): bool;

    public function claims(): array;
}
