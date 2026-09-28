<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys;

/**
 * @internal skeleton V1 para 082 — NO realiza validación criptográfica real,
 *           solo emite challenges y simula assertion success.
 * @todo implementar signature counter increments y validación authData en 083.
 */
final class PasskeyAssertionCeremony
{
    public function __construct(
        private readonly RelyingPartyConfig $rp,
    ) {
    }

    /**
     * @return array{challenge: string, rp_id: string, user_handle: string|null}
     */
    public function beginAssertion(?string $userHandle = null): array
    {
        $challenge = bin2hex(random_bytes(32));
        return [
            'challenge' => $challenge,
            'rp_id' => $this->rp->rpId,
            'user_handle' => $userHandle,
        ];
    }

    /**
     * @param array<string, mixed> $response
     */
    public function finishAssertion(array $response): AssertionResult
    {
        $credentialId = is_string($response['credential_id'] ?? null) ? trim((string) $response['credential_id']) : null;
        $userHandle = is_string($response['user_handle'] ?? null) ? trim((string) $response['user_handle']) : null;
        $prevSignCount = isset($response['previous_sign_count']) && is_numeric($response['previous_sign_count'])
            ? (int) $response['previous_sign_count']
            : 0;

        $valid = $credentialId !== null && $credentialId !== '';
        $incremented = $prevSignCount + 1;

        return new AssertionResult(
            isValid: $valid,
            credentialId: $credentialId,
            userHandle: $userHandle,
            signCountIncremented: $valid ? $incremented : 0,
            metadata: [
                'rp_id' => $this->rp->rpId,
                'simulated' => true,
                'skeleton_version' => '082_v1',
            ],
        );
    }
}
