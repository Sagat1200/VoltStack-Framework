<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

/**
 * Canonical evidence kind strings used by the built-in verifiers and the
 * `RecoveryEvidence::$kind` field. Downstream code can introduce custom
 * kinds by using its own non-empty strings; built-ins are only a
 * convention.
 */
final class RecoveryEvidenceKind
{
    public const MfaTotpCode = 'mfa_totp_code';
    public const PasskeyAssertion = 'passkey_assertion';
    public const AdminRecoveryReference = 'admin_recovery_reference';
    public const RecoveryCode = 'recovery_code';
    public const FederatedLink = 'federated_link';
}
