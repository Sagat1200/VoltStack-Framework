<?php

declare(strict_types=1);

namespace Quantum\Auth\Federation\Oidc;

use Quantum\Auth\Identity\GenericIdentity;
use Quantum\Auth\Identity\IdentityIdentifier;
use Quantum\Auth\Identity\IdentitySecurityState;

/**
 * @internal skeleton V1 — heurística básica security_state según email_verified.
 */
final class FederatedClaimsMapper
{
    /**
     * @param array<string, mixed> $claims
     * @return array{identity: GenericIdentity, security_state: IdentitySecurityState, attributes: array<string, mixed>}
     */
    public function toGenericIdentity(array $claims): array
    {
        $sub = is_string($claims['sub'] ?? null) && trim((string) $claims['sub']) !== ''
            ? trim((string) $claims['sub'])
            : (isset($claims['sub']) ? (string) $claims['sub'] : ('federated_' . bin2hex(random_bytes(8))));

        $iss = is_string($claims['iss'] ?? null) ? $claims['iss'] : '';
        $identifier = $iss !== '' ? ($iss . '|' . $sub) : $sub;

        $email = is_string($claims['email'] ?? null) ? $claims['email'] : null;
        $emailVerified = true;
        if (array_key_exists('email_verified', $claims)) {
            $emailVerified = (bool) $claims['email_verified'];
        }

        $securityState = $emailVerified ? IdentitySecurityState::Active : IdentitySecurityState::Suspended;

        $preferredUsername = is_string($claims['preferred_username'] ?? null) ? $claims['preferred_username'] : null;
        $name = is_string($claims['name'] ?? null) ? $claims['name'] : null;

        $attributes = [
            'federated_issuer' => $iss,
            'federated_sub' => $sub,
            'federated_aud' => $claims['aud'] ?? null,
            'email' => $email,
            'email_verified' => $emailVerified,
            'preferred_username' => $preferredUsername,
            'name' => $name,
            '_provider_identifier_value' => $email ?? ($preferredUsername ?? $identifier),
            'identity_type' => 'federated_oidc',
        ];

        $identity = new GenericIdentity(
            identifier: new IdentityIdentifier($identifier),
            type: 'federated_user',
            attributes: $attributes,
        );

        return [
            'identity' => $identity,
            'security_state' => $securityState,
            'attributes' => $attributes,
        ];
    }
}
