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
use Quantum\Auth\Contracts\RecoveryAuditLoggerInterface;
use Quantum\Auth\Contracts\RecoveryCodeStoreInterface;
use Quantum\Auth\Contracts\RecoveryEvidenceVerifierInterface;
use Quantum\Auth\Contracts\RecoveryManagerInterface;
use Quantum\Auth\Contracts\RecoveryNotificationDispatcherInterface;
use Quantum\Auth\Contracts\RecoveryTokenRepositoryInterface;
use Quantum\Auth\Contracts\TrustedDeviceRepositoryInterface;
use Quantum\Auth\Exceptions\RecoveryEvidenceRejectedException;
use Quantum\Auth\Exceptions\RecoveryPasswordRejectedException;
use Quantum\Auth\Exceptions\RecoveryPasswordReuseException;
use Quantum\Auth\Exceptions\RecoveryTokenExpiredException;
use Quantum\Auth\Exceptions\RecoveryTokenInvalidException;
use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Config\ConfigRepository;

final class RecoveryManager implements RecoveryManagerInterface
{
    /**
     * @param iterable<int, RecoveryEvidenceVerifierInterface> $evidenceVerifiers
     */
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
        private readonly RecoveryNotificationDispatcherInterface $notifications,
        private readonly RecoveryAuditLoggerInterface $audit,
        private readonly iterable $evidenceVerifiers = [],
        private readonly ?PasskeyCredentialStoreInterface $passkeys = null,
        private readonly ?PasswordLifecycleAwareProviderInterface $passwordLifecycle = null,
        private readonly ?DistributedPasswordGovernanceProviderInterface $governance = null,
        private readonly ?RecoveryCodeStoreInterface $recoveryCodes = null,
    ) {}

    /**
     * Creates a recovery token on behalf of an identity without going through
     * the self-service identifier discovery flow. This is intended for
     * administrative recovery consoles or federated-link recovery flows that
     * already hold a known identity.
     *
     * The purpose drives the behaviour:
     *   - `RecoveryPurpose::AdminPasswordReset` behaves like the regular
     *     password reset path but does NOT broadcast a recovery requested
     *     notification to the end user (so admin consoles don't implicitly
     *     spam the user); notification of completion IS sent so the user
     *     knows a password change happened.
     *   - `RecoveryPurpose::PasswordReset` behaves exactly like `begin()`
     *     for a self-service password reset.
     *
     * @param IdentityInterface $identity the identity to recover (required).
     * @param array<string, mixed> $attributes copied verbatim into token attributes.
     */
    public function createRecoveryForIdentity(
        IdentityInterface $identity,
        RecoveryPurpose $purpose = RecoveryPurpose::AdminPasswordReset,
        string $transport = 'admin',
        array $attributes = [],
    ): RecoveryStartResult {
        $this->tokens->deleteExpired();

        $primaryIdentifier = (string) ($identity->attributes['_provider_identifier_value'] ?? $identity->identifier());
        $identifier = $primaryIdentifier;
        $passwordHash = $this->identities->passwordHashFor($identity);
        $supportedPurposes = [
            RecoveryPurpose::PasswordReset,
            RecoveryPurpose::AdminPasswordReset,
            RecoveryPurpose::FederatedLinkRecovery,
        ];
        if (! in_array($purpose, $supportedPurposes, true)) {
            $this->audit(new RecoveryAuditEvent(
                eventId: $this->eventId(),
                action: RecoveryAuditEvent::ACTION_UNSUPPORTED_PURPOSE,
                purpose: $purpose,
                occurredAt: time(),
                identity: new IdentityReference($identity->identifier(), $identity->type()),
                transport: $transport,
                result: 'ignored',
                payload: $attributes,
                correlationId: $attributes['correlation_id'] ?? null,
            ));

            return new RecoveryStartResult(
                accepted: true,
                purpose: $purpose,
                issued: false,
                delivery: 'unsupported',
            );
        }

        if (! is_string($passwordHash) || trim($passwordHash) === '') {
            $this->audit(new RecoveryAuditEvent(
                eventId: $this->eventId(),
                action: RecoveryAuditEvent::ACTION_REQUEST_INVALID_PASSWORD_BASELINE,
                purpose: $purpose,
                occurredAt: time(),
                identity: new IdentityReference($identity->identifier(), $identity->type()),
                destination: $this->resolveRecoveryDestination($identity, $identifier),
                transport: $transport,
                result: 'admin_recovery_invalid_password_baseline',
                payload: $attributes,
                correlationId: $attributes['correlation_id'] ?? null,
            ));

            return new RecoveryStartResult(
                accepted: true,
                purpose: $purpose,
                issued: false,
                delivery: $this->deliveryChannel(),
            );
        }

        [$tokenId, $secret, $secretHash] = $this->issueTokenMaterial();
        $issuedAt = time();
        $expiresAt = $issuedAt + $this->passwordResetTtlSeconds();
        $deliveryToken = $tokenId . '.' . $secret;

        $this->tokens->save(new PasswordResetTokenRecord(
            id: $tokenId,
            secretHash: $secretHash,
            reference: new IdentityReference($identity->identifier(), $identity->type()),
            identifier: $identifier,
            purpose: $purpose,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            attributes: $attributes,
        ));

        $deliveryDispatched = false;
        if ($purpose === RecoveryPurpose::PasswordReset) {
            $deliveryDispatched = $this->dispatchPasswordResetRequestedNotification(
                $identity,
                new RecoveryRequest(
                    purpose: $purpose,
                    identifier: $identifier,
                    transport: $transport,
                    attributes: $attributes,
                ),
                $deliveryToken,
                $expiresAt,
            );
        }

        $this->audit(new RecoveryAuditEvent(
            eventId: $this->eventId(),
            action: RecoveryAuditEvent::ACTION_REQUESTED,
            purpose: $purpose,
            occurredAt: time(),
            identity: new IdentityReference($identity->identifier(), $identity->type()),
            recoveryTokenId: $tokenId,
            destination: $this->resolveRecoveryDestination($identity, $identifier),
            transport: $transport,
            result: 'admin_issued',
            payload: [
                'delivery_channel' => $this->deliveryChannel(),
                'delivery_dispatched' => $deliveryDispatched,
                'expires_at' => $expiresAt,
            ] + $attributes,
            correlationId: $attributes['correlation_id'] ?? null,
        ));

        return new RecoveryStartResult(
            accepted: true,
            purpose: $purpose,
            issued: true,
            expiresAt: $expiresAt,
            delivery: $this->deliveryChannel(),
            token: $deliveryToken,
            metadata: [
                'transport' => $transport,
                'token_preview_enabled' => true,
                'notification_dispatched' => $deliveryDispatched,
                'created_for_identity' => true,
            ],
        );
    }

    public function begin(RecoveryRequest $request): RecoveryStartResult
    {
        $this->tokens->deleteExpired();

        if (! in_array($request->purpose, [
            RecoveryPurpose::PasswordReset,
            RecoveryPurpose::AdminPasswordReset,
            RecoveryPurpose::FederatedLinkRecovery,
        ], true)) {
            $this->audit(new RecoveryAuditEvent(
                eventId: $this->eventId(),
                action: RecoveryAuditEvent::ACTION_UNSUPPORTED_PURPOSE,
                purpose: $request->purpose,
                occurredAt: time(),
                transport: $request->transport,
                result: 'ignored',
                payload: $request->attributes,
                correlationId: $request->attributes['correlation_id'] ?? null,
            ));

            return new RecoveryStartResult(
                accepted: true,
                purpose: $request->purpose,
                issued: false,
                delivery: 'unsupported',
            );
        }

        $identifier = trim($request->identifier);
        if ($identifier === '') {
            $this->audit(new RecoveryAuditEvent(
                eventId: $this->eventId(),
                action: RecoveryAuditEvent::ACTION_REQUEST_EMPTY,
                purpose: $request->purpose,
                occurredAt: time(),
                transport: $request->transport,
                result: 'accepted_but_no_identifier',
                payload: $request->attributes,
                correlationId: $request->attributes['correlation_id'] ?? null,
            ));

            return new RecoveryStartResult(
                accepted: true,
                purpose: $request->purpose,
                issued: false,
                delivery: $this->deliveryChannel(),
            );
        }

        $identity = $this->identities->findByIdentifier($identifier);
        if (! $identity instanceof IdentityInterface) {
            $this->audit(new RecoveryAuditEvent(
                eventId: $this->eventId(),
                action: RecoveryAuditEvent::ACTION_NOT_FOUND,
                purpose: $request->purpose,
                occurredAt: time(),
                transport: $request->transport,
                result: 'accepted_but_identity_not_found',
                payload: [
                    'requested_identifier' => $identifier,
                ] + $request->attributes,
                correlationId: $request->attributes['correlation_id'] ?? null,
            ));

            return new RecoveryStartResult(
                accepted: true,
                purpose: $request->purpose,
                issued: false,
                delivery: $this->deliveryChannel(),
            );
        }

        $passwordHash = $this->identities->passwordHashFor($identity);
        if (! is_string($passwordHash) || trim($passwordHash) === '') {
            $this->audit(new RecoveryAuditEvent(
                eventId: $this->eventId(),
                action: RecoveryAuditEvent::ACTION_REQUEST_INVALID_PASSWORD_BASELINE,
                purpose: $request->purpose,
                occurredAt: time(),
                identity: new IdentityReference($identity->identifier(), $identity->type()),
                destination: $this->resolveRecoveryDestination($identity, $identifier),
                transport: $request->transport,
                result: 'accepted_but_invalid_password_baseline',
                payload: $request->attributes,
                correlationId: $request->attributes['correlation_id'] ?? null,
            ));

            return new RecoveryStartResult(
                accepted: true,
                purpose: $request->purpose,
                issued: false,
                delivery: $this->deliveryChannel(),
            );
        }

        [$tokenId, $secret, $secretHash] = $this->issueTokenMaterial();
        $issuedAt = time();
        $expiresAt = $issuedAt + $this->passwordResetTtlSeconds();

        $deliveryToken = $tokenId . '.' . $secret;

        $this->tokens->save(new PasswordResetTokenRecord(
            id: $tokenId,
            secretHash: $secretHash,
            reference: new \Quantum\Auth\Identity\IdentityReference($identity->identifier(), $identity->type()),
            identifier: $identifier,
            purpose: $request->purpose,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            attributes: $request->attributes,
        ));

        $deliveryDispatched = $this->dispatchPasswordResetRequestedNotification(
            $identity,
            $request,
            $deliveryToken,
            $expiresAt,
        );

        $this->audit(new RecoveryAuditEvent(
            eventId: $this->eventId(),
            action: RecoveryAuditEvent::ACTION_REQUESTED,
            purpose: $request->purpose,
            occurredAt: time(),
            identity: new IdentityReference($identity->identifier(), $identity->type()),
            recoveryTokenId: $tokenId,
            destination: $this->resolveRecoveryDestination($identity, $identifier),
            transport: $request->transport,
            result: 'issued',
            payload: [
                'delivery_channel' => $this->deliveryChannel(),
                'delivery_dispatched' => $deliveryDispatched,
                'expires_at' => $expiresAt,
            ] + $request->attributes,
            correlationId: $request->attributes['correlation_id'] ?? null,
        ));

        return new RecoveryStartResult(
            accepted: true,
            purpose: $request->purpose,
            issued: true,
            expiresAt: $expiresAt,
            delivery: $this->deliveryChannel(),
            token: $this->exposeToken() ? $deliveryToken : null,
            metadata: [
                'transport' => $request->transport,
                'token_preview_enabled' => $this->exposeToken(),
                'notification_dispatched' => $deliveryDispatched,
            ],
        );
    }

    public function continueRecovery(RecoveryContinuationRequest $request): RecoveryResult
    {
        $this->tokens->deleteExpired();

        $supportedPurposes = [
            RecoveryPurpose::PasswordReset,
            RecoveryPurpose::AdminPasswordReset,
            RecoveryPurpose::FederatedLinkRecovery,
        ];
        if (! in_array($request->purpose, $supportedPurposes, true)) {
            $exception = new RecoveryTokenInvalidException('The requested recovery purpose is not supported by this recovery flow.');

            $this->audit(new RecoveryAuditEvent(
                eventId: $this->eventId(),
                action: RecoveryAuditEvent::ACTION_UNSUPPORTED_PURPOSE,
                purpose: $request->purpose,
                occurredAt: time(),
                transport: $request->transport,
                result: 'rejected_unsupported_purpose',
                payload: $request->attributes,
                correlationId: $request->attributes['correlation_id'] ?? null,
            ));

            throw $exception;
        }

        $identity = null;
        $tokenId = null;
        $destination = null;

        try {
            [$tokenId, $secret] = $this->parseToken($request->token);
            $record = $this->tokens->find($tokenId);

            if (! $record instanceof PasswordResetTokenRecord) {
                $exception = new RecoveryTokenInvalidException();

                $this->audit(new RecoveryAuditEvent(
                    eventId: $this->eventId(),
                    action: RecoveryAuditEvent::ACTION_TOKEN_INVALID,
                    purpose: $request->purpose,
                    occurredAt: time(),
                    recoveryTokenId: $tokenId,
                    transport: $request->transport,
                    result: 'recovery_token_not_found',
                    payload: $request->attributes,
                    correlationId: $request->attributes['correlation_id'] ?? null,
                ));

                throw $exception;
            }

            if ($record->isConsumed()) {
                $exception = new RecoveryTokenInvalidException();

                $this->audit(new RecoveryAuditEvent(
                    eventId: $this->eventId(),
                    action: RecoveryAuditEvent::ACTION_TOKEN_CONSUMED,
                    purpose: $request->purpose,
                    occurredAt: time(),
                    identity: $record->reference,
                    recoveryTokenId: $record->id,
                    transport: $request->transport,
                    result: 'recovery_token_already_consumed',
                    payload: [
                        'consumed_at' => $record->consumedAt,
                    ] + $request->attributes,
                    correlationId: $request->attributes['correlation_id'] ?? null,
                ));

                throw $exception;
            }

            if ($record->isExpired()) {
                $exception = new RecoveryTokenExpiredException();

                $this->audit(new RecoveryAuditEvent(
                    eventId: $this->eventId(),
                    action: RecoveryAuditEvent::ACTION_TOKEN_EXPIRED,
                    purpose: $request->purpose,
                    occurredAt: time(),
                    identity: $record->reference,
                    recoveryTokenId: $record->id,
                    transport: $request->transport,
                    result: 'recovery_token_expired',
                    payload: [
                        'expires_at' => $record->expiresAt,
                    ] + $request->attributes,
                    correlationId: $request->attributes['correlation_id'] ?? null,
                ));

                throw $exception;
            }

            if (! hash_equals($record->secretHash, hash('sha256', $secret))) {
                $exception = new RecoveryTokenInvalidException();

                $this->audit(new RecoveryAuditEvent(
                    eventId: $this->eventId(),
                    action: RecoveryAuditEvent::ACTION_TOKEN_INVALID,
                    purpose: $request->purpose,
                    occurredAt: time(),
                    identity: $record->reference,
                    recoveryTokenId: $record->id,
                    transport: $request->transport,
                    result: 'recovery_token_secret_mismatch',
                    payload: $request->attributes,
                    correlationId: $request->attributes['correlation_id'] ?? null,
                ));

                throw $exception;
            }

            $identity = $this->identities->findByIdentifier($record->identifier);
            if (! $identity instanceof IdentityInterface) {
                $exception = new RecoveryTokenInvalidException();

                $this->audit(new RecoveryAuditEvent(
                    eventId: $this->eventId(),
                    action: RecoveryAuditEvent::ACTION_TOKEN_INVALID,
                    purpose: $request->purpose,
                    occurredAt: time(),
                    identity: $record->reference,
                    recoveryTokenId: $record->id,
                    transport: $request->transport,
                    result: 'identity_no_longer_exists',
                    payload: $request->attributes,
                    correlationId: $request->attributes['correlation_id'] ?? null,
                ));

                throw $exception;
            }

            if (
                $identity->type() !== $record->reference->type
                || (string) $identity->identifier() !== $record->reference->identifier->value
            ) {
                $exception = new RecoveryTokenInvalidException();

                $this->audit(new RecoveryAuditEvent(
                    eventId: $this->eventId(),
                    action: RecoveryAuditEvent::ACTION_TOKEN_INVALID,
                    purpose: $request->purpose,
                    occurredAt: time(),
                    identity: $record->reference,
                    recoveryTokenId: $record->id,
                    transport: $request->transport,
                    result: 'identity_reference_mismatch',
                    payload: $request->attributes,
                    correlationId: $request->attributes['correlation_id'] ?? null,
                ));

                throw $exception;
            }

            $currentHash = $this->identities->passwordHashFor($identity);
            if (! is_string($currentHash) || trim($currentHash) === '') {
                $exception = new RecoveryTokenInvalidException();

                $this->audit(new RecoveryAuditEvent(
                    eventId: $this->eventId(),
                    action: RecoveryAuditEvent::ACTION_TOKEN_INVALID,
                    purpose: $request->purpose,
                    occurredAt: time(),
                    identity: new IdentityReference($identity->identifier(), $identity->type()),
                    recoveryTokenId: $record->id,
                    transport: $request->transport,
                    result: 'password_baseline_missing',
                    payload: $request->attributes,
                    correlationId: $request->attributes['correlation_id'] ?? null,
                ));

                throw $exception;
            }

            $evidenceResults = $this->verifyRecoveryEvidences($request, $record, new IdentityReference($identity->identifier(), $identity->type()));
            $evidenceRejection = array_values(array_filter(
                $evidenceResults,
                static fn (array $result): bool => ! ($result['skip'] ?? false) && ! ($result['passed'] ?? false),
            ));

            if ($evidenceRejection !== []) {
                $exception = RecoveryEvidenceRejectedException::forResults(
                    evidenceResults: $evidenceResults,
                    metadata: [
                        'recovery_token_id' => $record->id,
                        'identity_type' => $record->reference->type,
                        'identity_identifier' => $record->reference->identifier->value,
                    ],
                );

                $this->audit(new RecoveryAuditEvent(
                    eventId: $this->eventId(),
                    action: RecoveryAuditEvent::ACTION_EVIDENCE_REJECTED,
                    purpose: $request->purpose,
                    occurredAt: time(),
                    identity: new IdentityReference($identity->identifier(), $identity->type()),
                    recoveryTokenId: $record->id,
                    transport: $request->transport,
                    result: 'recovery_evidence_rejected',
                    payload: [
                        'evidence_results' => $evidenceResults,
                    ] + $request->attributes,
                    correlationId: $request->attributes['correlation_id'] ?? null,
                ));

                throw $exception;
            }

            $newPassword = trim($request->newPassword);
            if ($newPassword === '' || ! $this->passwordPolicy->accepts($newPassword)) {
                $exception = new RecoveryPasswordRejectedException();

                $this->audit(new RecoveryAuditEvent(
                    eventId: $this->eventId(),
                    action: RecoveryAuditEvent::ACTION_PASSWORD_REJECTED,
                    purpose: $request->purpose,
                    occurredAt: time(),
                    identity: new IdentityReference($identity->identifier(), $identity->type()),
                    recoveryTokenId: $record->id,
                    transport: $request->transport,
                    result: 'password_did_not_meet_policy',
                    payload: $request->attributes,
                    correlationId: $request->attributes['correlation_id'] ?? null,
                ));

                throw $exception;
            }

            if ($this->passwordPolicy->verify($newPassword, $currentHash)) {
                $exception = new RecoveryPasswordReuseException();

                $this->audit(new RecoveryAuditEvent(
                    eventId: $this->eventId(),
                    action: RecoveryAuditEvent::ACTION_PASSWORD_REUSE,
                    purpose: $request->purpose,
                    occurredAt: time(),
                    identity: new IdentityReference($identity->identifier(), $identity->type()),
                    recoveryTokenId: $record->id,
                    transport: $request->transport,
                    result: 'password_matches_current_hash',
                    payload: $request->attributes,
                    correlationId: $request->attributes['correlation_id'] ?? null,
                ));

                throw $exception;
            }

            if ($this->passwordLifecycle instanceof PasswordLifecycleAwareProviderInterface) {
                $metadata = $this->passwordLifecycle->passwordLifecycleMetadataFor($identity);
                $history = is_array($metadata['password_rotation_history'] ?? null)
                    ? $metadata['password_rotation_history']
                    : [];

                if (! $this->passwordPolicy->checkAgainstHistory($newPassword, $history)) {
                    $exception = new RecoveryPasswordReuseException();

                    $this->audit(new RecoveryAuditEvent(
                        eventId: $this->eventId(),
                        action: RecoveryAuditEvent::ACTION_PASSWORD_REUSE,
                        purpose: $request->purpose,
                        occurredAt: time(),
                        identity: new IdentityReference($identity->identifier(), $identity->type()),
                        recoveryTokenId: $record->id,
                        transport: $request->transport,
                        result: 'password_matches_rotation_history',
                        payload: [
                            'history_size' => count($history),
                        ] + $request->attributes,
                        correlationId: $request->attributes['correlation_id'] ?? null,
                    ));

                    throw $exception;
                }
            }
        } catch (
            RecoveryTokenInvalidException
            | RecoveryTokenExpiredException
            | RecoveryPasswordRejectedException
            | RecoveryPasswordReuseException
            | RecoveryEvidenceRejectedException $exception
        ) {
            throw $exception;
        }

        $newHash = $this->passwordPolicy->hash($newPassword);
        if (! $this->passwordRehashing->upgradePasswordHash($identity, $newHash)) {
            $persistException = new RecoveryPasswordRejectedException('The recovery flow could not persist the new password hash.');

            $this->audit(new RecoveryAuditEvent(
                eventId: $this->eventId(),
                action: RecoveryAuditEvent::ACTION_PASSWORD_REJECTED,
                purpose: $request->purpose,
                occurredAt: time(),
                identity: new IdentityReference($identity->identifier(), $identity->type()),
                recoveryTokenId: $tokenId,
                transport: $request->transport,
                result: 'password_hash_persistence_failed',
                payload: $request->attributes,
                correlationId: $request->attributes['correlation_id'] ?? null,
            ));

            throw $persistException;
        }

        $this->mutableIdentities->clearFailedAuthentication($identity);
        $this->mutableIdentities->unlock($identity);
        $this->tokens->markConsumed($tokenId ?? '');

        $this->governance?->saveRotationReceipt(
            identity: $identity,
            previousHash: $currentHash,
            newHash: $newHash,
            rotatedAt: time(),
            rotatedByActorSessionPublicId: 'recovery-reset:' . ($tokenId ?? ''),
            reason: 'password_reset_recovery',
        );

        $revokedSessions = count($this->sessions->listForIdentity($identity));
        $this->sessions->deleteForIdentity($identity);
        $tokensRevoked = $this->opaqueTokens->revokeAllForIdentity($identity->type(), (string) $identity->identifier());
        $trustedDevicesRevoked = $this->revokeTrustedDevicesForIdentity($identity);
        $passkeysRevoked = $this->revokePasskeysForIdentity($identity, $record->identifier ?? '');
        $recoveryCodesRotated = $this->rotateRecoveryCodesForIdentity($identity);
        $notificationDispatched = $this->dispatchPasswordResetCompletedNotification(
            $identity,
            $request,
            $revokedSessions,
            $tokensRevoked,
            $trustedDevicesRevoked,
            $passkeysRevoked,
        );
        $destination = $this->resolveRecoveryDestination($identity, '');

        $this->audit(new RecoveryAuditEvent(
            eventId: $this->eventId(),
            action: RecoveryAuditEvent::ACTION_COMPLETED,
            purpose: $request->purpose,
            occurredAt: time(),
            identity: new IdentityReference($identity->identifier(), $identity->type()),
            recoveryTokenId: $tokenId,
            destination: $destination !== '' ? $destination : null,
            transport: $request->transport,
            result: 'password_reset_completed',
            payload: [
                'delivery_channel' => $this->deliveryChannel(),
                'delivery_dispatched' => $notificationDispatched,
                'sessions_revoked' => $revokedSessions,
                'tokens_revoked' => $tokensRevoked,
                'trusted_devices_revoked' => $trustedDevicesRevoked,
                'passkeys_revoked' => $passkeysRevoked,
                'recovery_codes_rotated' => $recoveryCodesRotated,
            ] + $request->attributes,
            correlationId: $request->attributes['correlation_id'] ?? null,
        ));

        return new RecoveryResult(
            completed: true,
            purpose: $request->purpose,
            identityType: $identity->type(),
            identityId: (string) $identity->identifier(),
            sessionsRevoked: $revokedSessions,
            tokensRevoked: $tokensRevoked,
            trustedDevicesRevoked: $trustedDevicesRevoked,
            passkeysRevoked: $passkeysRevoked,
            completedAt: time(),
            metadata: [
                'transport' => $request->transport,
                'recovery_token_id' => $tokenId,
                'notification_dispatched' => $notificationDispatched,
                'recovery_codes_rotated' => $recoveryCodesRotated,
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

    private function rotateRecoveryCodesForIdentity(IdentityInterface $identity): int
    {
        if (! $this->recoveryCodes instanceof RecoveryCodeStoreInterface) {
            return 0;
        }
        if (! (bool) $this->config->get('auth.recovery.password_reset.rotate_recovery_codes', true)) {
            return 0;
        }
        $reference = new IdentityReference($identity->identifier(), $identity->type());

        return $this->recoveryCodes->rotateForIdentity($reference);
    }

    private function invalidateTrustedDevices(): bool
    {
        return (bool) $this->config->get('auth.recovery.password_reset.invalidate_trusted_devices', true);
    }

    private function invalidatePasskeys(): bool
    {
        return (bool) $this->config->get('auth.recovery.password_reset.invalidate_passkeys', true);
    }

    private function dispatchPasswordResetRequestedNotification(
        IdentityInterface $identity,
        RecoveryRequest $request,
        string $deliveryToken,
        int $expiresAt,
    ): bool {
        $destination = $this->resolveRecoveryDestination($identity, $request->identifier);
        if ($destination === '' || ! $this->notificationsEnabled()) {
            return false;
        }

        return $this->notifications->dispatch(new RecoveryNotification(
            type: RecoveryNotificationType::PasswordResetRequested,
            purpose: RecoveryPurpose::PasswordReset,
            identityType: $identity->type(),
            identityId: (string) $identity->identifier(),
            destination: $destination,
            channel: $this->deliveryChannel(),
            transport: $request->transport,
            createdAt: time(),
            payload: array_filter([
                'reset_token' => $deliveryToken,
                'expires_at' => $expiresAt,
                'reset_url' => $this->buildResetUrl($deliveryToken),
            ], static fn (mixed $value): bool => $value !== null),
            metadata: $request->attributes,
        ));
    }

    private function dispatchPasswordResetCompletedNotification(
        IdentityInterface $identity,
        RecoveryContinuationRequest $request,
        int $sessionsRevoked,
        int $tokensRevoked,
        int $trustedDevicesRevoked,
        int $passkeysRevoked,
    ): bool {
        $destination = $this->resolveRecoveryDestination($identity, '');
        if ($destination === '' || ! $this->notificationsEnabled()) {
            return false;
        }

        return $this->notifications->dispatch(new RecoveryNotification(
            type: RecoveryNotificationType::PasswordResetCompleted,
            purpose: RecoveryPurpose::PasswordReset,
            identityType: $identity->type(),
            identityId: (string) $identity->identifier(),
            destination: $destination,
            channel: $this->deliveryChannel(),
            transport: $request->transport,
            createdAt: time(),
            payload: [
                'sessions_revoked' => $sessionsRevoked,
                'tokens_revoked' => $tokensRevoked,
                'trusted_devices_revoked' => $trustedDevicesRevoked,
                'passkeys_revoked' => $passkeysRevoked,
            ],
            metadata: $request->attributes,
        ));
    }

    private function notificationsEnabled(): bool
    {
        return strtolower(trim((string) $this->config->get('auth.recovery.notifications.driver', 'noop'))) !== 'noop';
    }

    private function resolveRecoveryDestination(IdentityInterface $identity, string $fallbackIdentifier): string
    {
        if ($identity instanceof \Quantum\Auth\Identity\GenericIdentity) {
            foreach (['email', 'identifier', 'username', '_provider_identifier_value'] as $key) {
                $candidate = $identity->attributes[$key] ?? null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    return trim($candidate);
                }
            }
        }

        return trim($fallbackIdentifier);
    }

    /**
     * @return list<array{kind:string,passed:bool,skip:bool,reason_code:string,metadata:array<string,mixed>}>
     */
    private function verifyRecoveryEvidences(
        RecoveryContinuationRequest $request,
        PasswordResetTokenRecord $record,
        IdentityReference $identityReference,
    ): array {
        $rawEvidences = $request->attributes['recovery_evidences'] ?? [];
        $configuredRequiredKinds = $this->config->get('auth.recovery.evidence.required_kinds', []);
        $requiredKinds = is_array($configuredRequiredKinds) ? $configuredRequiredKinds : [];
        $requiredKinds = array_values(array_filter(
            $requiredKinds,
            static fn (mixed $kind): bool => is_string($kind) && trim($kind) !== '',
        ));

        // For admin-initiated recovery flows the admin reference kind is
        // implicitly required unless the caller explicitly configured
        // required kinds (in which case the configuration wins).
        if (
            $record->purpose === RecoveryPurpose::AdminPasswordReset
            && $requiredKinds === []
        ) {
            $requiredKinds = [RecoveryEvidenceKind::AdminRecoveryReference];
        }

        if (
            $record->purpose === RecoveryPurpose::FederatedLinkRecovery
            && $requiredKinds === []
        ) {
            $requiredKinds = [RecoveryEvidenceKind::FederatedLink];
        }

        if (! is_array($rawEvidences) || $rawEvidences === []) {
            $out = [];

            foreach ($requiredKinds as $kind) {
                $out[] = RecoveryEvidenceVerificationResult::failed(
                    (string) $kind,
                    'recovery.evidence.missing_required',
                )->toArray();
            }

            return $out;
        }

        $evidences = [];
        foreach ($rawEvidences as $raw) {
            if ($raw instanceof RecoveryEvidence) {
                $evidences[] = $raw;
                continue;
            }

            if (is_array($raw)) {
                $evidences[] = RecoveryEvidence::fromArray($raw);
            }
        }

        $results = [];
        $verifiers = $this->evidenceVerifiers instanceof \Traversable
            ? iterator_to_array($this->evidenceVerifiers, false)
            : (array) $this->evidenceVerifiers;

        foreach ($evidences as $evidence) {
            $matched = null;
            foreach ($verifiers as $verifier) {
                if (! $verifier instanceof RecoveryEvidenceVerifierInterface) {
                    continue;
                }

                $result = $verifier->verify($evidence, $identityReference, $record);

                if (! $result->skip) {
                    $matched = $result;
                    break;
                }
            }

            if ($matched === null) {
                $results[] = RecoveryEvidenceVerificationResult::failed(
                    $evidence->kind,
                    'recovery.evidence.unsupported_kind',
                )->toArray();
                continue;
            }

            $results[] = $matched->toArray();
        }

        // Ensure every required kind has a non-skipped pass. If multiple
        // evidences of the same kind are present, one passing evidence is
        // sufficient.
        foreach ($requiredKinds as $kind) {
            $matching = array_values(array_filter(
                $results,
                static fn (array $result): bool => ($result['kind'] ?? '') === $kind && ! ($result['skip'] ?? false),
            ));

            if ($matching === []) {
                $results[] = RecoveryEvidenceVerificationResult::failed(
                    (string) $kind,
                    'recovery.evidence.missing_required',
                )->toArray();
                continue;
            }

            $passed = array_filter(
                $matching,
                static fn (array $result): bool => (bool) ($result['passed'] ?? false),
            );

            if ($passed === []) {
                // Already a failure present; keep it.
            }
        }

        return $results;
    }

    private function buildResetUrl(string $deliveryToken): ?string
    {
        $configured = $this->config->get('auth.recovery.notifications.reset_url');

        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        $configured = trim($configured);

        if (str_contains($configured, '{token}')) {
            return str_replace('{token}', rawurlencode($deliveryToken), $configured);
        }

        $separator = str_contains($configured, '?') ? '&' : '?';

        return $configured . $separator . 'token=' . rawurlencode($deliveryToken);
    }

    private function audit(RecoveryAuditEvent $event): bool
    {
        if (! $this->auditEnabled()) {
            return true;
        }

        return $this->audit->log($event);
    }

    private function auditEnabled(): bool
    {
        return strtolower(trim((string) $this->config->get('auth.recovery.audit.driver', 'noop'))) !== 'noop';
    }

    private function eventId(): string
    {
        return 'recovery-' . bin2hex(random_bytes(12));
    }
}
