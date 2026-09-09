<?php

declare(strict_types=1);

namespace Quantum\Auth\Devices;

use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Config\ConfigRepository;

final class TrustedDeviceCredentialValidator
{
    public function __construct(
        private readonly TrustedDeviceRepositoryInterface $repository,
        private readonly ConfigRepository $config,
    ) {}

    public function validate(
        ?string $credential,
        IdentityReference $reference,
        ?string $deviceReference,
    ): TrustedDeviceCredentialValidationResult {
        $payload = $this->parseCredential($credential);

        if ($payload === null) {
            return new TrustedDeviceCredentialValidationResult(
                credentialPresented: is_string($credential) && trim($credential) !== '',
                invalid: is_string($credential) && trim($credential) !== '',
            );
        }

        $trustedDevice = $this->repository->find($payload['public_id']);

        if (
            $trustedDevice === null
            || $trustedDevice->isExpired()
            || $deviceReference === null
            || $trustedDevice->deviceReference !== $deviceReference
            || $trustedDevice->reference->type !== $reference->type
            || $trustedDevice->reference->identifier->value !== $reference->identifier->value
        ) {
            return new TrustedDeviceCredentialValidationResult(
                credentialPresented: true,
                invalid: true,
            );
        }

        $secretHash = $this->hashSecret($payload['secret']);
        $credentialHash = $trustedDevice->attributes['credential_hash'] ?? null;

        if (is_string($credentialHash) && hash_equals($credentialHash, $secretHash)) {
            return new TrustedDeviceCredentialValidationResult(
                device: $trustedDevice,
                credentialPresented: true,
            );
        }

        $previousHash = $trustedDevice->attributes['previous_credential_hash'] ?? null;

        if (is_string($previousHash) && hash_equals($previousHash, $secretHash)) {
            if ($this->revokeOnReplay()) {
                $this->repository->delete($trustedDevice->publicId->value);
            }

            return new TrustedDeviceCredentialValidationResult(
                credentialPresented: true,
                invalid: true,
                replayed: true,
            );
        }

        return new TrustedDeviceCredentialValidationResult(
            credentialPresented: true,
            invalid: true,
        );
    }

    private function revokeOnReplay(): bool
    {
        return (bool) $this->config->get('auth.trusted_devices.rotation.revoke_on_replay', true);
    }

    /**
     * @return array{public_id: string, secret: string}|null
     */
    private function parseCredential(?string $credential): ?array
    {
        if (! is_string($credential) || trim($credential) === '') {
            return null;
        }

        $parts = explode('.', trim($credential), 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$publicId, $secret] = $parts;
        $publicId = trim($publicId);
        $secret = trim($secret);

        if ($publicId === '' || $secret === '' || ! str_starts_with($publicId, 'tdv_')) {
            return null;
        }

        return [
            'public_id' => $publicId,
            'secret' => $secret,
        ];
    }

    private function hashSecret(string $secret): string
    {
        return hash_hmac('sha256', $secret, $this->deviceReferenceSalt());
    }

    private function deviceReferenceSalt(): string
    {
        $configured = $this->config->get('auth.session.device.reference_salt');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $appKey = $this->config->get('app.key');

        if (is_string($appKey) && trim($appKey) !== '') {
            return trim($appKey);
        }

        return 'voltstack-auth-device-reference';
    }
}
