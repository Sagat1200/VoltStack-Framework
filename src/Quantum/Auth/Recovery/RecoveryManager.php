<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\DistributedPasswordGovernanceProviderInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\MutableIdentityProviderInterface;
use Quantum\Auth\Contracts\OpaqueTokenRepositoryInterface;
use Quantum\Auth\Contracts\PasskeyCredentialStoreInterface;
use Quantum\Auth\Contracts\PasswordLifecycleAwareProviderInterface;
use Quantum\Auth\Contracts\PasswordPolicyInterface;
use Quantum\Auth\Contracts\PasswordRehashingIdentityProviderInterface;
use Quantum\Auth\Contracts\RecoveryManagerInterface;
use Quantum\Auth\Contracts\RecoveryTokenRepositoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Exceptions\RecoveryPasswordRejectedException;
use Quantum\Auth\Exceptions\RecoveryPasswordReuseException;
use Quantum\Auth\Exceptions\RecoveryTokenExpiredException;
use Quantum\Auth\Exceptions\RecoveryTokenInvalidException;
use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Config\ConfigRepository;

final class RecoveryManager implements RecoveryManagerInterface
{
    public function __construct(
        private readonly IdentityProviderInterface $identities,
        private readonly PasswordPolicyInterface $passwordPolicy,
        private readonly MutableIdentityProviderInterface $mutableIdentities,
        private readonly PasswordRehashingIdentityProviderInterface $passwordRehashing,
        private readonly RecoveryTokenRepositoryInterface $tokens,
        private readonly AuthenticationSessionRepositoryInterface $sessions,
        private readonly OpaqueTokenRepositoryInterface $opaqueTokens,
        private readonly ConfigRepository $config,
        private readonly TrustedDeviceRepositoryInterface $trustedDevices,
        private readonly ?PasskeyCredentialStoreInterface $passkeys = null,
        private readonly ?PasswordLifecycleAwareProviderInterface $passwordLifecycle = null,
        private readonly ?DistributedPasswordGovernanceProviderInterface $governance = null,
    ) {}

    public function begin(RecoveryRequest $request): RecoveryStartResult
    {
        $this->tokens->deleteExpired();

        if ($request->purpose !== RecoveryPurpose::PasswordReset) {
            return new RecoveryStartResult(
                accepted: true,
                purpose: $request->purpose,
                issued: false,
                delivery: 'unsupported',
            );
        }

        $identifier = trim($request->identifier);
        if ($identifier === '') {
            return new RecoveryStartResult(
                accepted: true,
                purpose: RecoveryPurpose::PasswordReset,
                issued: false,
                delivery: $this->deliveryChannel(),
            );
        }

        $identity = $this->identities->findByIdentifier($identifier);
        if (! $identity instanceof IdentityInterface) {
            return new RecoveryStartResult(
                accepted: true,
                purpose: RecoveryPurpose::PasswordReset,
                issued: false,
                delivery: $this->deliveryChannel(),
            );
        }

        $passwordHash = $this->identities->passwordHashFor($identity);
        if (! is_string($passwordHash) || trim($passwordHash) === '') {
            return new RecoveryStartResult(
                accepted: true,
                purpose: RecoveryPurpose::PasswordReset,
                issued: false,
                delivery: $this->deliveryChannel(),
            );
        }

        [$tokenId, $secret, $secretHash] = $this->issueTokenMaterial();
        $issuedAt = time();
        $expiresAt = $issuedAt + $this->passwordResetTtlSeconds();

        $this->tokens->save(new PasswordResetTokenRecord(
            id: $tokenId,
            secretHash: $secretHash,
            reference: new \Quantum\Auth\Identity\IdentityReference($identity->identifier(), $identity->type()),
            identifier: $identifier,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            attributes: $request->attributes,
        ));

        return new RecoveryStartResult(
            accepted: true,
            purpose: RecoveryPurpose::PasswordReset,
            issued: true,
            expiresAt: $expiresAt,
            delivery: $this->deliveryChannel(),
            token: $this->exposeToken() ? $tokenId . '.' . $secret : null,
            metadata: [
                'transport' => $request->transport,
                'token_preview_enabled' => $this->exposeToken(),
            ],
        );
    }

    public function continueRecovery(RecoveryContinuationRequest $request): RecoveryResult
    {
        $this->tokens->deleteExpired();

        if ($request->purpose !== RecoveryPurpose::PasswordReset) {
            throw new RecoveryTokenInvalidException('The requested recovery purpose is not supported by this recovery flow.');
        }

        [$tokenId, $secret] = $this->parseToken($request->token);
        $record = $this->tokens->find($tokenId);

        if (! $record instanceof PasswordResetTokenRecord || $record->isConsumed()) {
            throw new RecoveryTokenInvalidException();
        }

        if ($record->isExpired()) {
            throw new RecoveryTokenExpiredException();
        }

        if (! hash_equals($record->secretHash, hash('sha256', $secret))) {
            throw new RecoveryTokenInvalidException();
        }

        $identity = $this->identities->findByIdentifier($record->identifier);
        if (! $identity instanceof IdentityInterface) {
            throw new RecoveryTokenInvalidException();
        }

        if (
            $identity->type() !== $record->reference->type
            || (string) $identity->identifier() !== $record->reference->identifier->value
        ) {
            throw new RecoveryTokenInvalidException();
        }

        $currentHash = $this->identities->passwordHashFor($identity);
        if (! is_string($currentHash) || trim($currentHash) === '') {
            throw new RecoveryTokenInvalidException();
        }

        $newPassword = trim($request->newPassword);
        if ($newPassword === '' || ! $this->passwordPolicy->accepts($newPassword)) {
            throw new RecoveryPasswordRejectedException();
        }

        if ($this->passwordPolicy->verify($newPassword, $currentHash)) {
            throw new RecoveryPasswordReuseException();
        }

        if ($this->passwordLifecycle instanceof PasswordLifecycleAwareProviderInterface) {
            $metadata = $this->passwordLifecycle->passwordLifecycleMetadataFor($identity);
            $history = is_array($metadata['password_rotation_history'] ?? null)
                ? $metadata['password_rotation_history']
                : [];

            if (! $this->passwordPolicy->checkAgainstHistory($newPassword, $history)) {
                throw new RecoveryPasswordReuseException();
            }
        }

        $newHash = $this->passwordPolicy->hash($newPassword);
        if (! $this->passwordRehashing->upgradePasswordHash($identity, $newHash)) {
            throw new RecoveryPasswordRejectedException('The recovery flow could not persist the new password hash.');
        }

        $this->mutableIdentities->clearFailedAuthentication($identity);
        $this->mutableIdentities->unlock($identity);
        $this->tokens->markConsumed($record->id);

        $this->governance?->saveRotationReceipt(
            identity: $identity,
            previousHash: $currentHash,
            newHash: $newHash,
            rotatedAt: time(),
            rotatedByActorSessionPublicId: 'recovery-reset:' . $record->id,
            reason: 'password_reset_recovery',
        );

        $revokedSessions = count($this->sessions->listForIdentity($identity));
        $this->sessions->deleteForIdentity($identity);
        $tokensRevoked = $this->opaqueTokens->revokeAllForIdentity($identity->type(), (string) $identity->identifier());
        $trustedDevicesRevoked = $this->revokeTrustedDevicesForIdentity($identity);
        $passkeysRevoked = $this->revokePasskeysForIdentity($identity, $record->identifier);

        return new RecoveryResult(
            completed: true,
            purpose: RecoveryPurpose::PasswordReset,
            identityType: $identity->type(),
            identityId: (string) $identity->identifier(),
            sessionsRevoked: $revokedSessions,
            tokensRevoked: $tokensRevoked,
            trustedDevicesRevoked: $trustedDevicesRevoked,
            passkeysRevoked: $passkeysRevoked,
            completedAt: time(),
            metadata: [
                'transport' => $request->transport,
                'recovery_token_id' => $record->id,
            ],
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseToken(string $token): array
    {
        $token = trim($token);
        if ($token === '' || ! str_contains($token, '.')) {
            throw new RecoveryTokenInvalidException();
        }

        [$tokenId, $secret] = explode('.', $token, 2);
        $tokenId = trim($tokenId);
        $secret = trim($secret);

        if ($tokenId === '' || $secret === '') {
            throw new RecoveryTokenInvalidException();
        }

        return [$tokenId, $secret];
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function issueTokenMaterial(): array
    {
        $tokenId = bin2hex(random_bytes(12));
        $secret = bin2hex(random_bytes(24));

        return [$tokenId, $secret, hash('sha256', $secret)];
    }

    private function passwordResetTtlSeconds(): int
    {
        $ttl = $this->config->get('auth.recovery.password_reset.ttl_seconds', 900);

        return is_numeric($ttl) ? max(60, (int) $ttl) : 900;
    }

    private function deliveryChannel(): string
    {
        $channel = $this->config->get('auth.recovery.password_reset.delivery', 'manual');

        return is_string($channel) && trim($channel) !== ''
            ? strtolower(trim($channel))
            : 'manual';
    }

    private function exposeToken(): bool
    {
        return (bool) $this->config->get('auth.recovery.password_reset.expose_token', false);
    }

    private function revokeTrustedDevicesForIdentity(IdentityInterface $identity): int
    {
        if (! $this->invalidateTrustedDevices()) {
            return 0;
        }

        $reference = new IdentityReference($identity->identifier(), $identity->type());
        $revoked = 0;

        foreach ($this->trustedDevices->listForIdentity($reference) as $device) {
            $this->trustedDevices->delete($device->publicId->value);
            $revoked++;
        }

        return $revoked;
    }

    private function revokePasskeysForIdentity(IdentityInterface $identity, string $lookupIdentifier): int
    {
        if (! $this->invalidatePasskeys() || ! $this->passkeys instanceof PasskeyCredentialStoreInterface) {
            return 0;
        }

        $userHandle = '';

        if ($identity instanceof \Quantum\Auth\Identity\GenericIdentity) {
            $candidate = $identity->attributes['_provider_identifier_value'] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                $userHandle = trim($candidate);
            }
        }

        if ($userHandle === '') {
            $userHandle = trim($lookupIdentifier);
        }

        if ($userHandle === '') {
            return 0;
        }

        $revoked = 0;
        foreach ($this->passkeys->listForUserHandle($userHandle) as $credential) {
            if ($this->passkeys->revoke($credential->credentialId)) {
                $revoked++;
            }
        }

        return $revoked;
    }

    private function invalidateTrustedDevices(): bool
    {
        return (bool) $this->config->get('auth.recovery.password_reset.invalidate_trusted_devices', true);
    }

    private function invalidatePasskeys(): bool
    {
        return (bool) $this->config->get('auth.recovery.password_reset.invalidate_passkeys', true);
    }
}
