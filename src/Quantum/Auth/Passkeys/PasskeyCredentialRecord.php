<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys;

/**
 * @internal skeleton V1 — no-op criptografía para 082
 */
final readonly class PasskeyCredentialRecord
{
    /**
     * @param list<string> $transports
     */
    public function __construct(
        public string $credentialId,
        public string $credentialPublicKey,
        public string $userHandle,
        public string $rpId,
        public int $signCount = 0,
        public int $createdAt = 0,
        public array $transports = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'credential_id' => $this->credentialId,
            'credential_public_key' => $this->credentialPublicKey,
            'user_handle' => $this->userHandle,
            'rp_id' => $this->rpId,
            'sign_count' => $this->signCount,
            'created_at' => $this->createdAt,
            'transports' => $this->transports,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            credentialId: (string) ($input['credential_id'] ?? ''),
            credentialPublicKey: (string) ($input['credential_public_key'] ?? ''),
            userHandle: (string) ($input['user_handle'] ?? ''),
            rpId: (string) ($input['rp_id'] ?? 'localhost'),
            signCount: isset($input['sign_count']) && is_numeric($input['sign_count']) ? (int) $input['sign_count'] : 0,
            createdAt: isset($input['created_at']) && is_numeric($input['created_at']) ? (int) $input['created_at'] : time(),
            transports: array_values(array_filter(
                is_array($input['transports'] ?? null) ? $input['transports'] : [],
                static fn (mixed $t): bool => is_string($t) && trim($t) !== '',
            )),
        );
    }
}
