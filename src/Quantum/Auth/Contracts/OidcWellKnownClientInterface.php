<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Federation\Oidc\OidcProviderMetadata;

interface OidcWellKnownClientInterface
{
    public function fetchConfiguration(string $issuerUrl): OidcProviderMetadata;
}
