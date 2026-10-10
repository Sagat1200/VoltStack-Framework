<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryEvidenceVerifierInterface;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Recovery\RecoveryEvidenceKind as Kind;

/**
 * Verifies that a recovery continuation was explicitly requested by an
 * administrator through a bound reference token.
 *
 * Lifecycle summary:
 *   1. Admin issues a signed or pre-stored admin reference. Today the
 *      minimal, pluggable contract expects the reference to contain a
 *      HMAC-style token composed of
 *      `base64url(identity_identifier) . "." . mac` where mac is
 *      `hash_hmac(algo, "{tokenId}.{identity}", secret)`.
 *   2. {@see RecoveryManager::begin()} accepts `RecoveryPurpose::AdminPasswordReset`
 *      and stores the token id alongside the token record so the verifier
 *      can confirm the reference belongs to the exact recovery attempt.
 *   3. When the end-user calls continueRecovery with evidence kind
 *      `RecoveryEvidenceKind::AdminRecoveryReference` the verifier checks
 *      the MAC, the identity binding and the token binding.
 *
 * A simpler non-HMAC fallback (useful for tests or local admin consoles)
 * accepts a plain-text reference pre-stored via `attributes['admin_reference']`
 * on the RecoveryRequest and compared using `hash_equals`.
 */
final class AdminRecoveryEvidenceVerifier implements RecoveryEvidenceVerifierInterface
{
    /**
     * @param list<string> $plainTextWhitelist        Known raw admin references
     *                                                  accepted without HMAC. Keep
     *                                                  empty in production.
     * @param non-empty-string $hmacSecret             Secret used for HMAC admin
     *                                                  references. Can be empty when
     *                                                  only the plain-text whitelist
     *                                                  path is used.
     * @param non-empty-string $hmacAlgo               algo accepted by hash_hmac
     */
    public function __construct(
        private readonly array $plainTextWhitelist = [],
        private readonly string $hmacSecret = '',
        private readonly string $hmacAlgo = 'sha256',
    ) {}

    public function verify(
        RecoveryEvidence $evidence,
        IdentityReference $identity,
        PasswordResetTokenRecord $token,
    ): RecoveryEvidenceVerificationResult {
        if ($evidence->kind !== Kind::AdminRecoveryReference) {
            return RecoveryEvidenceVerificationResult::skip($evidence->kind);
        }

        $value = trim($evidence->value);

        if ($value === '') {
            return RecoveryEvidenceVerificationResult::failed(
                $evidence->kind,
                'recovery.evidence.admin.empty_reference',
            );
        }

        $storedPlain = is_string($token->attributes['admin_reference'] ?? null)
            ? trim((string) $token->attributes['admin_reference'])
            : '';

        if ($storedPlain !== '' && hash_equals($storedPlain, $value)) {
            return RecoveryEvidenceVerificationResult::passed(
                $evidence->kind,
                'recovery.evidence.admin.plain_bound_to_token',
                ['binding' => 'token_plain_reference'],
            );
        }

        foreach ($this->plainTextWhitelist as $candidate) {
            if (is_string($candidate) && $candidate !== '' && hash_equals($candidate, $value)) {
                // Global whitelist only when token was explicitly created for
                // an admin purpose (avoids accepting a static reference on
                // a self-service password reset token).
                if (! in_array($token->purpose->value, [RecoveryPurpose::AdminPasswordReset->value, RecoveryPurpose::PasswordReset->value], true)) {
                    continue;
                }

                return RecoveryEvidenceVerificationResult::passed(
                    $evidence->kind,
                    'recovery.evidence.admin.plain_whitelisted',
                    ['binding' => 'global_whitelist'],
                );
            }
        }

        if ($this->hmacSecret === '') {
            return RecoveryEvidenceVerificationResult::failed(
                $evidence->kind,
                'recovery.evidence.admin.reference_mismatch',
            );
        }

        $parts = explode('.', $value, 2);

        if (count($parts) !== 2) {
            return RecoveryEvidenceVerificationResult::failed(
                $evidence->kind,
                'recovery.evidence.admin.reference_malformed',
            );
        }

        [$identityEncoded, $providedMac] = $parts;
        $identityDecoded = rawurldecode(base64_decode($identityEncoded, true) ?: '');

        if ($identityDecoded === '' || ! hash_equals($identityDecoded, $identity->identifier)) {
            return RecoveryEvidenceVerificationResult::failed(
                $evidence->kind,
                'recovery.evidence.admin.identity_binding_mismatch',
            );
        }

        $tokenId = method_exists($token, 'getId') ? $token->getId() : (string) ($token->id ?? $token->tokenId ?? '');
        $payload = $tokenId . '.' . $identity->identifier;
        $expectedMac = hash_hmac($this->hmacAlgo, $payload, $this->hmacSecret);

        if (! hash_equals($expectedMac, $providedMac)) {
            return RecoveryEvidenceVerificationResult::failed(
                $evidence->kind,
                'recovery.evidence.admin.hmac_invalid',
            );
        }

        return RecoveryEvidenceVerificationResult::passed(
            $evidence->kind,
            'recovery.evidence.admin.hmac_ok',
            ['binding' => 'hmac_token_identity'],
        );
    }
}
