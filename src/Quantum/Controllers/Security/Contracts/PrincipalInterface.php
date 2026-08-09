<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Contracts;

use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Controllers\Security\Context\PrincipalType;

/**
 * @api Contrato público estable del Principal autenticado (v0.12.x JWT-lite parsing).
 *
 * Implementación por defecto: `Principal` (readonly con claims array).
 */
interface PrincipalInterface
{
    public function id(): string;

    public function type(): PrincipalType;

    public function authenticated(): bool;

    public function claims(): array;
}
