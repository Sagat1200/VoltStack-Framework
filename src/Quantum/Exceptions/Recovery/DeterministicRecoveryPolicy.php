<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Recovery;

use Quantum\Exceptions\Context\RecoveryContext;
use Quantum\Exceptions\Contracts\RecoveryPolicyInterface;
use Quantum\Exceptions\Enums\Effect;
use Quantum\Exceptions\Enums\RecoveryAction;
use Quantum\Exceptions\Enums\RetryAdvice;
use Quantum\Exceptions\Model\ExceptionDescriptor;
use Quantum\Exceptions\Model\RecoveryDecision;

final class DeterministicRecoveryPolicy implements RecoveryPolicyInterface
{
    public function decide(ExceptionDescriptor $error, RecoveryContext $context): RecoveryDecision
    {
        if ($error->semantic->code === 'operation.cancelled' || $context->cancelled) {
            return new RecoveryDecision(
                action: RecoveryAction::Abort,
                reasonCode: 'operation.cancelled',
            );
        }

        if (
            $error->semantic->retryAdvice === RetryAdvice::ReconcileFirst
            || in_array($error->semantic->effect, [Effect::Committed, Effect::Partial], true)
        ) {
            return new RecoveryDecision(
                action: RecoveryAction::Reconcile,
                reasonCode: $error->semantic->code,
            );
        }

        if (
            $error->semantic->retryAdvice === RetryAdvice::Conditional
            && $error->semantic->effect === Effect::None
            && $context->idempotencyVerified
        ) {
            return new RecoveryDecision(
                action: RecoveryAction::RetryAdvice,
                reasonCode: $error->semantic->code,
                delayMs: $context->attempt > 0 ? min(5000, $context->attempt * 250) : null,
            );
        }

        return new RecoveryDecision(
            action: RecoveryAction::None,
            reasonCode: $error->semantic->code,
        );
    }
}
