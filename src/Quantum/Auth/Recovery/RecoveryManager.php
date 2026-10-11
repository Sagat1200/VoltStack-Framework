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
use Quantum\Auth\Contracts\RecoveryTransactionStoreInterface;
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
        private readonly ?RecoveryTransactionStoreInterface $transactions = null,
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
                destination: $this->resolveRecoveryDestination($identity, $identifier, $this->deliveryChannel()),
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

        // Nonce de FederatedLink para replay protection del JWT de evidencia.
        if (! isset($attributes['nonce']) || ! is_string($attributes['nonce']) || trim($attributes['nonce']) === '') {
            $attributes['nonce'] = bin2hex(random_bytes(16));
        }

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

        // Crear transacción multi-step ligada al token para roll-forward
        // y rollback parcial explicable (solo si hay store configurado).
        $this->createTransactionForToken($tokenId);

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
            destination: $this->resolveRecoveryDestination($identity, $identifier, $this->deliveryChannel()),
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
                destination: $this->resolveRecoveryDestination($identity, $identifier, $this->deliveryChannel()),
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

        // Nonce de FederatedLink para replay protection del JWT de evidencia.
        $attributes = $request->attributes;
        if (! isset($attributes['nonce']) || ! is_string($attributes['nonce']) || trim($attributes['nonce']) === '') {
            $attributes['nonce'] = bin2hex(random_bytes(16));
        }

        $this->tokens->save(new PasswordResetTokenRecord(
            id: $tokenId,
            secretHash: $secretHash,
            reference: new \Quantum\Auth\Identity\IdentityReference($identity->identifier(), $identity->type()),
            identifier: $identifier,
            purpose: $request->purpose,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            attributes: $attributes,
        ));

        // Transacción multi-step ligada al token (roll-forward / rollback).
        $this->createTransactionForToken($tokenId);

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
            destination: $this->resolveRecoveryDestination($identity, $identifier, $this->deliveryChannel()),
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
        $transaction = null;
        $currentStep = null;

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

            // Cargar transacción multi-step y marcar token_validation como
            // completado (token, identidad y password baseline verificados).
            $transaction = $this->loadTransaction($record->id);
            if ($transaction instanceof RecoveryTransaction) {
                if (! $transaction->isStepCompleted(RecoveryTransaction::STEP_TOKEN_VALIDATION)) {
                    $transaction = $transaction->markStepCompleted(RecoveryTransaction::STEP_TOKEN_VALIDATION);
                    $this->persistTransaction($transaction);
                }
            }

            // Paso evidence_verification: roll-forward si ya completó.
            $currentStep = RecoveryTransaction::STEP_EVIDENCE_VERIFICATION;
            $evidenceStepCompleted = $transaction instanceof RecoveryTransaction
                && $transaction->isStepCompleted(RecoveryTransaction::STEP_EVIDENCE_VERIFICATION);

            if (! $evidenceStepCompleted) {
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

                if ($transaction instanceof RecoveryTransaction) {
                    $transaction = $transaction->markStepCompleted(
                        RecoveryTransaction::STEP_EVIDENCE_VERIFICATION,
                        ['evidence_kinds' => array_column($evidenceResults, 'kind')],
                    );
                    $this->persistTransaction($transaction);
                }
            }

            // Paso password_policy: roll-forward si ya completó.
            $currentStep = RecoveryTransaction::STEP_PASSWORD_POLICY;
            $passwordPolicyCompleted = $transaction instanceof RecoveryTransaction
                && $transaction->isStepCompleted(RecoveryTransaction::STEP_PASSWORD_POLICY);

            if (! $passwordPolicyCompleted) {
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

                if ($transaction instanceof RecoveryTransaction) {
                    $transaction = $transaction->markStepCompleted(RecoveryTransaction::STEP_PASSWORD_POLICY);
                    $this->persistTransaction($transaction);
                }
            } else {
                // Roll-forward: la contraseña ya fue validada en un intento
                // anterior; recuperamos el valor del request para los pasos
                // posteriores (credential_persistence la re-usará).
                $newPassword = trim($request->newPassword);
            }
        } catch (
            RecoveryTokenInvalidException
            | RecoveryTokenExpiredException
            | RecoveryPasswordRejectedException
            | RecoveryPasswordReuseException
            | RecoveryEvidenceRejectedException $exception
        ) {
            // Rollback parcial de pasos pre-commit completados y marcado
            // del paso fallido para explicabilidad audit/step-level.
            if ($transaction instanceof RecoveryTransaction && is_string($currentStep)) {
                $transaction = $transaction
                    ->markStepFailed($currentStep, $exception->getMessage())
                    ->rollbackReversible();
                $this->persistTransaction($transaction);
            }

            throw $exception;
        }

        // Paso credential_persistence (commit point): roll-forward si ya
        // completó en un intento anterior (la credencial ya cambió).
        $credentialPersisted = $transaction instanceof RecoveryTransaction
            && $transaction->isStepCompleted(RecoveryTransaction::STEP_CREDENTIAL_PERSISTENCE);
        $newHash = '';

        if (! $credentialPersisted) {
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

                if ($transaction instanceof RecoveryTransaction) {
                    $transaction = $transaction->markStepFailed(
                        RecoveryTransaction::STEP_CREDENTIAL_PERSISTENCE,
                        'password_hash_persistence_failed',
                    );
                    $this->persistTransaction($transaction);
                }

                throw $persistException;
            }

            if ($transaction instanceof RecoveryTransaction) {
                $transaction = $transaction->markStepCompleted(RecoveryTransaction::STEP_CREDENTIAL_PERSISTENCE);
                $this->persistTransaction($transaction);
            }
        }

        // Paso state_cleanup (post-commit, best-effort): limpiar estado de
        // autenticación fallida, desbloquear y guardar recibo de governance.
        $currentStep = RecoveryTransaction::STEP_STATE_CLEANUP;
        try {
            $this->mutableIdentities->clearFailedAuthentication($identity);
            $this->mutableIdentities->unlock($identity);

            if ($newHash !== '') {
                $this->governance?->saveRotationReceipt(
                    identity: $identity,
                    previousHash: $currentHash,
                    newHash: $newHash,
                    rotatedAt: time(),
                    rotatedByActorSessionPublicId: 'recovery-reset:' . ($tokenId ?? ''),
                    reason: 'password_reset_recovery',
                );
            }

            if ($transaction instanceof RecoveryTransaction) {
                $transaction = $transaction->markStepCompleted(RecoveryTransaction::STEP_STATE_CLEANUP);
            }
        } catch (\Throwable $e) {
            if ($transaction instanceof RecoveryTransaction) {
                $transaction = $transaction->markStepFailed(
                    RecoveryTransaction::STEP_STATE_CLEANUP,
                    $e->getMessage(),
                );
            }
        }
        $this->persistTransaction($transaction);

        // Paso session_revocation (post-commit, best-effort, idempotente).
        $revokedSessions = 0;
        $tokensRevoked = 0;
        $trustedDevicesRevoked = 0;
        $passkeysRevoked = 0;
        $currentStep = RecoveryTransaction::STEP_SESSION_REVOCATION;
        try {
            $revokedSessions = count($this->sessions->listForIdentity($identity));
            $this->sessions->deleteForIdentity($identity);
            $tokensRevoked = $this->opaqueTokens->revokeAllForIdentity($identity->type(), (string) $identity->identifier());
            $trustedDevicesRevoked = $this->revokeTrustedDevicesForIdentity($identity);
            $passkeysRevoked = $this->revokePasskeysForIdentity($identity, $record->identifier ?? '');

            if ($transaction instanceof RecoveryTransaction) {
                $transaction = $transaction->markStepCompleted(RecoveryTransaction::STEP_SESSION_REVOCATION, [
                    'sessions_revoked' => $revokedSessions,
                    'tokens_revoked' => $tokensRevoked,
                    'trusted_devices_revoked' => $trustedDevicesRevoked,
                    'passkeys_revoked' => $passkeysRevoked,
                ]);
            }
        } catch (\Throwable $e) {
            if ($transaction instanceof RecoveryTransaction) {
                $transaction = $transaction->markStepFailed(
                    RecoveryTransaction::STEP_SESSION_REVOCATION,
                    $e->getMessage(),
                );
            }
        }
        $this->persistTransaction($transaction);

        // Paso recovery_code_rotation (post-commit, best-effort, idempotente).
        $recoveryCodesRotated = 0;
        $currentStep = RecoveryTransaction::STEP_RECOVERY_CODE_ROTATION;
        try {
            $recoveryCodesRotated = $this->rotateRecoveryCodesForIdentity($identity);

            if ($transaction instanceof RecoveryTransaction) {
                $transaction = $transaction->markStepCompleted(
                    RecoveryTransaction::STEP_RECOVERY_CODE_ROTATION,
                    ['recovery_codes_rotated' => $recoveryCodesRotated],
                );
            }
        } catch (\Throwable $e) {
            if ($transaction instanceof RecoveryTransaction) {
                $transaction = $transaction->markStepFailed(
                    RecoveryTransaction::STEP_RECOVERY_CODE_ROTATION,
                    $e->getMessage(),
                );
            }
        }
        $this->persistTransaction($transaction);

        // Paso notification (post-commit, roll-forward para no duplicar envíos).
        $notificationDispatched = false;
        $notificationAlreadyDone = $transaction instanceof RecoveryTransaction
            && $transaction->isStepCompleted(RecoveryTransaction::STEP_NOTIFICATION);
        $currentStep = RecoveryTransaction::STEP_NOTIFICATION;

        if ($notificationAlreadyDone) {
            $notificationDispatched = true;
        } else {
            try {
                $notificationDispatched = $this->dispatchPasswordResetCompletedNotification(
                    $identity,
                    $request,
                    $revokedSessions,
                    $tokensRevoked,
                    $trustedDevicesRevoked,
                    $passkeysRevoked,
                );

                if ($transaction instanceof RecoveryTransaction) {
                    $transaction = $transaction->markStepCompleted(
                        RecoveryTransaction::STEP_NOTIFICATION,
                        ['notification_dispatched' => $notificationDispatched],
                    );
                }
            } catch (\Throwable $e) {
                if ($transaction instanceof RecoveryTransaction) {
                    $transaction = $transaction->markStepFailed(
                        RecoveryTransaction::STEP_NOTIFICATION,
                        $e->getMessage(),
                    );
                }
            }
        }
        $this->persistTransaction($transaction);

        // Paso token_consumption (final): marca el token como consumido para
        // evitar reutilización. Se ejecuta siempre (idempotente).
        $currentStep = RecoveryTransaction::STEP_TOKEN_CONSUMPTION;
        $this->tokens->markConsumed($tokenId ?? '');
        if ($transaction instanceof RecoveryTransaction) {
            $transaction = $transaction->markStepCompleted(RecoveryTransaction::STEP_TOKEN_CONSUMPTION);
        }

        $destination = $this->resolveRecoveryDestination($identity, '', $this->deliveryChannel());

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
                'transaction_steps' => $transaction instanceof RecoveryTransaction
                    ? $transaction->stepsToArray()
                    : null,
            ] + $request->attributes,
            correlationId: $request->attributes['correlation_id'] ?? null,
        ));

        if ($transaction instanceof RecoveryTransaction) {
            $this->persistTransaction($transaction);
        }

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
                'transaction_all_completed' => $transaction instanceof RecoveryTransaction
                    ? $transaction->allCompleted()
                    : null,
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

    /**
     * Crea y persiste una transacción multi-step nueva para un token.
     * No-op si no hay store configurado.
     */
    private function createTransactionForToken(string $tokenId): void
    {
        if (! $this->transactions instanceof RecoveryTransactionStoreInterface) {
            return;
        }

        try {
            $this->transactions->save(RecoveryTransaction::create($tokenId));
        } catch (\Throwable) {
            // Fallos de persistencia de transacción no deben romper el flujo
            // principal de recuperación; el store es best-effort.
        }
    }

    /**
     * Carga la transacción ligada al token o crea una nueva si no existe
     * (backward compat con tokens emitidos antes del modelo de transacción).
     */
    private function loadTransaction(string $tokenId): ?RecoveryTransaction
    {
        if (! $this->transactions instanceof RecoveryTransactionStoreInterface) {
            return null;
        }

        $transaction = $this->transactions->find($tokenId);
        if ($transaction instanceof RecoveryTransaction) {
            return $transaction;
        }

        $transaction = RecoveryTransaction::create($tokenId);
        $this->transactions->save($transaction);

        return $transaction;
    }

    private function persistTransaction(?RecoveryTransaction $transaction): void
    {
        if (! $transaction instanceof RecoveryTransaction) {
            return;
        }

        if (! $this->transactions instanceof RecoveryTransactionStoreInterface) {
            return;
        }

        try {
            $this->transactions->save($transaction);
        } catch (\Throwable) {
            // Best-effort: no romper el flujo principal.
        }
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
        $destination = $this->resolveRecoveryDestination($identity, $request->identifier, $this->deliveryChannel());
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
        $destination = $this->resolveRecoveryDestination($identity, '', $this->deliveryChannel());
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

    /**
     * Resuelve el destino de la notificación de recovery.
     *
     * Para identidades `GenericIdentity`, busca atributos de contacto de
     * recuperación específicos del canal (p. ej. `recovery_email`,
     * `recovery_phone`, `recovery_push_token`) y, si no existen, hace fallback
     * a atributos genéricos (`email`, `identifier`, `username`,
     * `_provider_identifier_value`). Para otros tipos de identidad, devuelve
     * el `$fallbackIdentifier`.
     */
    private function resolveRecoveryDestination(IdentityInterface $identity, string $fallbackIdentifier, string $channel = ''): string
    {
        if ($identity instanceof \Quantum\Auth\Identity\GenericIdentity) {
            $candidateKeys = $this->destinationKeysForChannel($channel);

            foreach ($candidateKeys as $key) {
                $candidate = $identity->attributes[$key] ?? null;
                if (is_string($candidate) && trim($candidate) !== '') {
                    return trim($candidate);
                }
            }
        }

        return trim($fallbackIdentifier);
    }

    /**
     * Devuelve la lista ordenada de claves de atributo a buscar como destino
     * según el canal de entrega.
     *
     * @return list<string>
     */
    private function destinationKeysForChannel(string $channel): array
    {
        return match (strtolower(trim($channel))) {
            'email' => [
                'recovery_email',
                'email',
                'mail',
                'identifier',
                'username',
                '_provider_identifier_value',
            ],
            'sms' => [
                'recovery_phone',
                'recovery_sms',
                'phone',
                'mobile',
                'phone_number',
                'identifier',
                'username',
                '_provider_identifier_value',
            ],
            'push' => [
                'recovery_push_token',
                'push_token',
                'device_token',
                'fcm_token',
                'identifier',
                '_provider_identifier_value',
            ],
            default => [
                'email',
                'identifier',
                'username',
                '_provider_identifier_value',
            ],
        };
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
