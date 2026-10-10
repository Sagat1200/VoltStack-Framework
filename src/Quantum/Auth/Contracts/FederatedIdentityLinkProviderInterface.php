<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Identity\IdentityReference;

/**
 * Proporciona mapping entre cuentas federadas (RP + subject) e identidades locales
 * para usarlo en evidence chain de recovery, unlink, security binding etc.
 *
 * Implementación mínima Local: lee `federated_links[]` del registro de identidad
 * en `LocalIdentityProvider` y retorna IdentityReference cuando hay coincidencia.
 */
interface FederatedIdentityLinkProviderInterface
{
    /**
     * Busca el enlace de una cuenta federada `(rpId, subject)` y devuelve la
     * referencia a la identidad local a la que está ligada, o `null` si no existe
     * ningún vínculo.
     *
     * @param string      $rpId    id del proveedor de identidad federado (issuer/resource_server
     *                             ej: `accounts.google.com`, `https://login.example.local`).
     * @param string      $subject identificador único persistente del usuario dentro del RP.
     */
    public function findByFederatedPair(string $rpId, string $subject): ?IdentityReference;

    /**
     * Devuelve `true` cuando la referencia de identidad aportada está vinculada al
     * par federado indicado. Es un helper semántico sin necesidad de exponer la
     * identidad al caller.
     */
    public function isLinkedTo(IdentityReference $identity, string $rpId, string $subject): bool;
}
