<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys;

/**
 * @internal skeleton V1 para 082 — NO realiza validación criptográfica real,
 *           solo construye payloads de challenge estructurales y simula éxito.
 * @todo implementar validación firma ES256/RS256 sobre COSE keys en 083.
 */
final class PasskeyRegistrationCeremony
{
    public function __construct(
        private readonly RelyingPartyConfig $rp,
    ) {
    }

    /**
     * @return array{challenge: string, rp: array{id: string, name: string}, user: array{id: string, name: string, display_name: string}}
     */
    public function beginRegistration(string $userHandle, string $userName, string $displayName): array
    {
        $challenge = bin2hex(random_bytes(32));

        return [
            'challenge' => $challenge,
            'rp' => [
                'id' => $this->rp->rpId,
                'name' => $this->rp->rpName,
            ],
            'user' => [
                'id' => $userHandle,
                'name' => $userName,
                'display_name' => $displayName,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    public function finishRegistration(array $response): PasskeyCredentialRecord
    {
        $credentialId = is_string($response['credential_id'] ?? null) && trim((string) $response['credential_id']) !== ''
            ? trim((string) $response['credential_id'])
            : 'reg_cred_' . bin2hex(random_bytes(16));
        $userHandle = is_string($response['user_handle'] ?? null) ? trim((string) $response['user_handle']) : 'anon_user';
        $publicKey = is_string($response['credential_public_key'] ?? null) && trim((string) $response['credential_public_key']) !== ''
            ? trim((string) $response['credential_public_key'])
            : 'simulated_pubkey_' . bin2hex(random_bytes(24));
        $transports = array_values(array_filter(
            is_array($response['transports'] ?? null) ? $response['transports'] : [],
            static fn (mixed $t): bool => is_string($t) && trim($t) !== '',
        ));

        return new PasskeyCredentialRecord(
            credentialId: $credentialId,
            credentialPublicKey: $publicKey,
            userHandle: $userHandle,
            rpId: $this->rp->rpId,
            signCount: 0,
            createdAt: time(),
            transports: $transports,
        );
    }
}
