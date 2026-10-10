<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Contracts\MultiFactorIdentityProviderInterface;
use Quantum\Auth\Contracts\RecoveryCodeStoreInterface;
use Quantum\Auth\Contracts\RecoveryEvidenceVerifierInterface;
use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Recovery\RecoveryEvidenceKind as Kind;

/**
 * Verifies MFA-style second factors submitted alongside recovery continuations.
 *
 * Accepts:
 *   - `RecoveryEvidenceKind::MfaTotpCode` with `value` = user-provided TOTP
 *     code or recovery code.
 *   - `RecoveryEvidenceKind::RecoveryCode` with `value` = a recovery code.
 *
 * Internally it resolves the identity from its reference (so we never
 * cross-check a factor against a different user's record) and delegates
 * to the identity provider when it implements {@see MultiFactorIdentityProviderInterface}.
 * If the provider does not support 2FA this verifier still accepts inputs
 * but always returns `failed` so the identity-provider policy is the
 * single source of truth (a provider without MFA support cannot vouch for
 * any 2FA evidence).
 */
final class MfaRecoveryEvidenceVerifier implements RecoveryEvidenceVerifierInterface
{
    private const SUPPORTED_KINDS = [
        Kind::MfaTotpCode,
        Kind::RecoveryCode,
    ];

    public function __construct(
        private readonly IdentityProviderInterface $identityProvider,
        private readonly ?RecoveryCodeStoreInterface $recoveryCodeStore = null,
    ) {}

    public function verify(
        RecoveryEvidence $evidence,
        IdentityReference $identity,
        PasswordResetTokenRecord $token,
    ): RecoveryEvidenceVerificationResult {
        if (! in_array($evidence->kind, self::SUPPORTED_KINDS, true)) {
            return RecoveryEvidenceVerificationResult::skip($evidence->kind);
        }

        $value = trim($evidence->value);

        if ($value === '') {
            return RecoveryEvidenceVerificationResult::failed(
                $evidence->kind,
                'recovery.evidence.mfa.empty',
            );
        }

        $method = $evidence->kind === Kind::RecoveryCode ? 'recovery_code' : 'totp';
        $provider = $this->identityProvider;

        $providerHasMfa = $provider instanceof MultiFactorIdentityProviderInterface;
        if ($providerHasMfa) {
            $resolved = $provider->findByIdentifier($token->identifier);

            if ($resolved !== null && $provider->supportsSecondFactor($resolved)) {
                $ok = $provider->verifySecondFactor($resolved, $value, $method);

                if ($ok) {
                    return RecoveryEvidenceVerificationResult::passed(
                        $evidence->kind,
                        'recovery.evidence.mfa.ok',
                        ['method' => $method, 'path' => 'provider'],
                    );
                }

                // Si falló el provider y el kind no es recovery code con store activo → devolver failed.
                if (! ($evidence->kind === Kind::RecoveryCode && $this->recoveryCodeStore !== null)) {
                    return RecoveryEvidenceVerificationResult::failed(
                        $evidence->kind,
                        'recovery.evidence.mfa.invalid',
                        ['method' => $method, 'path' => 'provider'],
                    );
                }
            } else {
                // El provider no soporta 2FA para la identidad.
                if ($evidence->kind === Kind::MfaTotpCode) {
                    return RecoveryEvidenceVerificationResult::failed(
                        $evidence->kind,
                        'recovery.evidence.mfa.not_enabled',
                        ['method' => $method],
                    );
                }
                // Para RecoveryCode, continuar al fallback del store.
            }
        }

        // Fallback: RecoveryCode kind con store pluggable. Útil cuando el IdentityProvider
        // no implementa MultiFactorIdentityProviderInterface pero los recovery codes sí
        // existen en el storage dedicado.
        if ($evidence->kind === Kind::RecoveryCode && $this->recoveryCodeStore !== null) {
            $candidate = $this->recoveryCodeStore->findUsableByCode($identity, $value);
            if ($candidate === null) {
                return RecoveryEvidenceVerificationResult::failed(
                    $evidence->kind,
                    'recovery.evidence.mfa.recovery_code_invalid',
                    ['path' => 'code_store'],
                );
            }
            $consumed = $this->recoveryCodeStore->consume($candidate);
            if ($consumed === null) {
                return RecoveryEvidenceVerificationResult::failed(
                    $evidence->kind,
                    'recovery.evidence.mfa.recovery_code_consume_failed',
                    ['path' => 'code_store'],
                );
            }

            return RecoveryEvidenceVerificationResult::passed(
                $evidence->kind,
                'recovery.evidence.mfa.recovery_code_consumed',
                ['method' => 'recovery_code', 'path' => 'code_store'],
            );
        }

        return RecoveryEvidenceVerificationResult::failed(
            $evidence->kind,
            'recovery.evidence.mfa.not_enabled',
            ['method' => $method],
        );
    }
}
