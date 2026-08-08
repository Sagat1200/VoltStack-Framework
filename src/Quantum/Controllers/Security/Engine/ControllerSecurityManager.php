<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Engine;

use Quantum\Http\Request;
use Quantum\Controllers\ControllerExecutionContext;
use Quantum\Controllers\Security\Context\ControllerSecurityContext;
use Quantum\Controllers\Security\Contracts\ControllerSecurityContextFactoryInterface;
use Quantum\Controllers\Security\Contracts\ControllerSecurityDecisionEngineInterface;
use Quantum\Controllers\Security\Contracts\ControllerSecurityManagerInterface;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;
use Quantum\Controllers\Security\Decision\SecurityDecisionEffect;
use Quantum\Controllers\Security\Exceptions\AuthenticationRequiredException;
use Quantum\Controllers\Security\Exceptions\AuthorizationDeniedException;
use Quantum\Controllers\Security\Exceptions\SecurityInfrastructureFailureException;

final class ControllerSecurityManager implements ControllerSecurityManagerInterface
{
    public function __construct(
        private readonly ControllerSecurityContextFactoryInterface $contextFactory,
        private readonly ControllerSecurityDecisionEngineInterface $decisionEngine,
    ) {}

    public function initialize(
        Request $request,
        ControllerExecutionContext $execution,
    ): ControllerSecurityContext {
        $context = $this->contextFactory->create($request, $execution);

        return $context;
    }

    public function authorize(SecurityEvaluationRequest $request): SecurityDecision
    {
        return $this->decisionEngine->decide($request);
    }

    public function assertAuthorized(SecurityEvaluationRequest $request): void
    {
        $decision = $this->decisionEngine->decide($request);

        switch ($decision->effect) {
            case SecurityDecisionEffect::Allow:
            case SecurityDecisionEffect::Abstain:
                return;
            case SecurityDecisionEffect::Challenge:
                $challengeMetadata = array_filter([
                    'obligations' => $decision->obligations,
                    'context' => $decision->context,
                ], static fn ($v) => ! empty($v));
                throw new AuthenticationRequiredException(
                    reasonCode: $decision->reasonCode ?: 'authentication_required',
                    challengeMetadata: $challengeMetadata,
                    safeContext: [
                        'policy_id' => $decision->policyId,
                        'reason_code' => $decision->reasonCode,
                        'required_strength_value' => $decision->obligations['required_strength_value'] ?? null,
                        'current_strength_value' => $decision->obligations['current_strength_value'] ?? null,
                    ],
                    message: sprintf(
                        'Authentication required by policy [%s]: %s',
                        $decision->policyId,
                        $decision->reasonCode ?: 'challenge',
                    ),
                );
            case SecurityDecisionEffect::Deny:
            default:
                throw new AuthorizationDeniedException(
                    reasonCode: $decision->reasonCode ?: 'deny_by_policy',
                    safeContext: array_merge([
                        'policy_id' => $decision->policyId,
                        'obligations' => $decision->obligations,
                    ], is_array($decision->context) ? $decision->context : []),
                    message: sprintf('Access denied by policy [%s]', $decision->policyId),
                );
        }
    }

    public function finalize(ControllerSecurityContext $context): void
    {
        $cleared = $context->decisions->clear();
    }
}
