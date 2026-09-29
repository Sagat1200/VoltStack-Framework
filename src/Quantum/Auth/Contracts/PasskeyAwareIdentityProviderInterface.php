<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Identity\IdentityInterface;

/**
 * Interfaz OPTIONAL para providers de identidad que pueden resolver una identidad por user_handle de passkey.
 *
 * - Si un provider implementa esta interfaz → PasskeyAuthenticator usa findByUserHandle() con prioridad.
 * - Si NO la implementa (compat baseline) → PasskeyAuthenticator hace fallback a findByIdentifier().
 *
 * Mantenida así para no romper el contrato IdentityProviderInterface existente (Hard Constraint).
 */
interface PasskeyAwareIdentityProviderInterface
{
    public function findByUserHandle(string $userHandle): ?IdentityInterface;
}
