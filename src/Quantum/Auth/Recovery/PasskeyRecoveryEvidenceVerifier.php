<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\PasskeyCredentialStoreInterface;
use Quantum\Auth\Contracts\RecoveryEvidenceVerifierInterface;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Passkeys\PasskeyAssertionCeremony;
use Quantum\Auth\Passkeys\RelyingPartyConfig;
use Quantum\Auth\Recovery\RecoveryEvidenceKind as Kind;

/**
 * Verifies evidence of kind {@see RecoveryEvidenceKind::PasskeyAssertion}
 * for a recovery continuation.
 *
 * The presented value must be a credential id (or base64url credential id)
 * owned by the recovered identity. This verifier is intentionally
 * lightweight compared to full WebAuthn assertion verification — it proves
 * that the caller currently controls a bound authenticator whose handle
 * matches the recovered user. For full cryptographic assertion the
 * metadata bag can optionally carry a full assertion response and this
 * verifier will forward it to {@see PasskeyAssertionCeremony} when
 * available.
 *
 * Metadata fields recognised when present:
 *   - `assertion_response`: array-shaped assertion payload forwarded to
 *     `PasskeyAssertionCeremony::verifyAssertion(...)`.
 *   - `rely_party_id`: override the default relying party id when
 *     evaluating the assertion response.
 *   - `origin`: override the expected origin when evaluating the
 *     assertion response.
 */
final class PasskeyRecoveryEvidenceVerifier implements RecoveryEvidenceVerifierInterface
{
    public function __construct(
        private readonly ?PasskeyCredentialStoreInterface $passkeyStore,
        private readonly ?PasskeyAssertionCeremony $assertionCeremony = null,
        private readonly ?RelyingPartyConfig $relyingPartyConfig = null,
    ) {}

    public function verify(
        RecoveryEvidence $evidence,
        IdentityReference $identity,
        PasswordResetTokenRecord $token,
    ): RecoveryEvidenceVerificationResult {
        if ($evidence->kind !== Kind::PasskeyAssertion) {
            return RecoveryEvidenceVerificationResult::skip($evidence->kind);
        }

        if ($this->passkeyStore === null) {
            return RecoveryEvidenceVerificationResult::failed(
                $evidence->kind,
                'recovery.evidence.passkey.store_unavailable',
            );
        }

        $credentialId = trim($evidence->value);

        if ($credentialId === '') {
            return RecoveryEvidenceVerificationResult::failed(
                $evidence->kind,
                'recovery.evidence.passkey.empty_credential',
            );
        }

        $record = $this->passkeyStore->findByCredentialId($credentialId);

        if ($record === null) {
            return RecoveryEvidenceVerificationResult::failed(
                $evidence->kind,
                'recovery.evidence.passkey.credential_not_found',
            );
        }

        if (! hash_equals($record->userHandle, (string) $identity->identifier)) {
            return RecoveryEvidenceVerificationResult::failed(
                $evidence->kind,
                'recovery.evidence.passkey.credential_mismatch',
            );
        }

        $assertionResponse = $evidence->metadata['assertion_response'] ?? null;
        if (! is_array($assertionResponse)) {
            // Credential ownership proof only.
            return RecoveryEvidenceVerificationResult::passed(
                $evidence->kind,
                'recovery.evidence.passkey.credential_bound',
                ['verification_mode' => 'credential_bound'],
            );
        }

        if ($this->assertionCeremony === null) {
            return RecoveryEvidenceVerificationResult::failed(
                $evidence->kind,
                'recovery.evidence.passkey.ceremony_unavailable',
                ['verification_mode' => 'assertion_response_received_but_ceremony_missing'],
            );
        }

        $rp = $this->relyingPartyConfig ?? new RelyingPartyConfig(
            rpId: (string) ($evidence->metadata['rely_party_id'] ?? 'localhost'),
            rpName: (string) ($evidence->metadata['rely_party_name'] ?? 'VoltStack'),
            origin: (string) ($evidence->metadata['origin'] ?? 'http://localhost'),
        );

        $expectedRpId = (string) ($evidence->metadata['rely_party_id'] ?? $rp->rpId);
        $expectedOrigin = (string) ($evidence->metadata['origin'] ?? $rp->origin);

        try {
            $result = $this->assertionCeremony->verifyAssertion(
                $assertionResponse,
                [$record->userHandle],
                $expectedRpId,
                $expectedOrigin,
            );
        } catch (\Throwable $e) {
            return RecoveryEvidenceVerificationResult::failed(
                $evidence->kind,
                'recovery.evidence.passkey.assertion_verification_failed',
                ['reason' => $e->getMessage()],
            );
        }

        if (! $result->signatureVerified || ! hash_equals((string) $result->credentialId, $record->credentialId)) {
            return RecoveryEvidenceVerificationResult::failed(
                $evidence->kind,
                'recovery.evidence.passkey.assertion_rejected',
                [
                    'signature_verified' => $result->signatureVerified,
                    'returned_credential' => $result->credentialId,
                ],
            );
        }

        return RecoveryEvidenceVerificationResult::passed(
            $evidence->kind,
            'recovery.evidence.passkey.assertion_ok',
            [
                'verification_mode' => 'assertion',
                'sign_count_incremented' => $result->signCountIncremented,
            ],
        );
    }
}
