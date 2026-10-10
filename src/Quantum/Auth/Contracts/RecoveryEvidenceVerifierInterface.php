<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Identity\IdentityReference;
use Quantum\Auth\Recovery\PasswordResetTokenRecord;
use Quantum\Auth\Recovery\RecoveryEvidence;
use Quantum\Auth\Recovery\RecoveryEvidenceVerificationResult;

/**
 * Verifies a single recovery evidence submitted during a password-reset
 * continuation or any other recovery workflow.
 *
 * Verifiers MUST be idempotent and MUST NOT mutate shared state. Stateful
 * side effects (for example incrementing a failed-attempt counter on an
 * external TOTP provider) belong in the caller or in a dedicated service,
 * not in the verifier itself.
 *
 * The orchestrator passes:
 *   - the evidence presented by the caller,
 *   - the target identity reference of the recovery flow (so verifiers can
 *     bound their lookup to the recovered user and avoid cross-user leaks),
 *   - the active recovery token record (so a verifier can bound results to
 *     the specific recovery attempt, for example an admin reference scoped
 *     to a single recovery).
 *
 * If a verifier does not understand the evidence kind it MUST return a
 * result with `skip=true` so the chain can continue.
 */
interface RecoveryEvidenceVerifierInterface
{
    public function verify(
        RecoveryEvidence $evidence,
        IdentityReference $identity,
        PasswordResetTokenRecord $token,
    ): RecoveryEvidenceVerificationResult;
}
