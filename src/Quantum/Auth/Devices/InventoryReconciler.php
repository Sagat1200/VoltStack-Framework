<?php

declare(strict_types=1);

namespace Quantum\Auth\Devices;

use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\InventoryReconcilerInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Sessions\AuthenticationSession;

final class InventoryReconciler implements InventoryReconcilerInterface
{
    public function __construct(
        private readonly AuthenticationSessionRepositoryInterface $sessions,
        private readonly TrustedDeviceRepositoryInterface $trustedDevices,
    ) {
    }

    public function reconcile(?int $now = null, bool $dryRun = false): array
    {
        $evaluatedAt = $now ?? time();

        $trustedIndex = [];

        foreach ($this->trustedDevices->all($evaluatedAt) as $device) {
            $trustedIndex[$this->trustedDeviceKey(
                $device->reference->type,
                $device->reference->identifier->value,
                $device->deviceReference,
            )] = $device->publicId->value;
        }

        $scanned = 0;
        $updated = 0;
        $promoted = 0;
        $demoted = 0;
        $skippedExpired = 0;
        $skippedWithoutDevice = 0;

        foreach ($this->sessions->all() as $session) {
            if ($session->isExpired($evaluatedAt)) {
                $skippedExpired++;
                continue;
            }

            $scanned++;
            $deviceReference = $this->sessionDeviceReference($session);

            if ($deviceReference === null) {
                $skippedWithoutDevice++;
                continue;
            }

            $trustedDevicePublicId = $trustedIndex[$this->trustedDeviceKey(
                $session->reference->type,
                $session->reference->identifier->value,
                $deviceReference,
            )] ?? null;

            $expectedTrusted = $trustedDevicePublicId !== null;
            $currentTrusted = $this->sessionTrustState($session) === 'trusted';
            $currentTrustedDevicePublicId = $this->sessionTrustedDevicePublicId($session);
            $currentCredentialPresent = (bool) ($session->attributes['trusted_device_credential_present'] ?? false);

            if (
                $currentTrusted === $expectedTrusted
                && $currentTrustedDevicePublicId === $trustedDevicePublicId
                && $currentCredentialPresent === $expectedTrusted
            ) {
                continue;
            }

            $updated++;
            $promoted += $expectedTrusted ? 1 : 0;
            $demoted += $expectedTrusted ? 0 : 1;

            if ($dryRun) {
                continue;
            }

            $this->sessions->touch(new AuthenticationSession(
                id: $session->id,
                identity: $session->identity,
                reference: $session->reference,
                method: $session->method,
                issuedAt: $session->issuedAt,
                expiresAt: $session->expiresAt,
                attributes: array_merge($session->attributes, [
                    'session_device_trust_state' => $expectedTrusted ? 'trusted' : 'unknown',
                    'trusted_device_public_id' => $trustedDevicePublicId,
                    'trusted_device_credential_present' => $expectedTrusted,
                ]),
            ));
        }

        return [
            'scanned' => $scanned,
            'updated' => $updated,
            'promoted' => $promoted,
            'demoted' => $demoted,
            'skipped_expired' => $skippedExpired,
            'skipped_without_device' => $skippedWithoutDevice,
            'evaluated_at' => $evaluatedAt,
        ];
    }

    private function sessionDeviceReference(AuthenticationSession $session): ?string
    {
        $value = $session->attributes['session_device_reference'] ?? null;

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function sessionTrustState(AuthenticationSession $session): string
    {
        $value = $session->attributes['session_device_trust_state'] ?? null;

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : 'unknown';
    }

    private function sessionTrustedDevicePublicId(AuthenticationSession $session): ?string
    {
        $value = $session->attributes['trusted_device_public_id'] ?? null;

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function trustedDeviceKey(string $type, string $identifier, string $deviceReference): string
    {
        return strtolower(trim($type)) . '|' . trim($identifier) . '|' . trim($deviceReference);
    }
}
