<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core;

use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Contracts\AuthorizationPlannerInterface;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;
use Quantum\Authorization\Contracts\AuthorizationManagerInterface;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Decision\AuthorizationDecisionPlan;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Authorization\Exceptions\AuthorizationChallengeException;
use Quantum\Authorization\Exceptions\AuthorizationDeniedException;
use Quantum\Authorization\Exceptions\AuthorizationEvaluationException;
use Quantum\Authorization\Principal\Principal;

final class AuthorizationManager implements AuthorizationManagerInterface
{
    public function __construct(
        private readonly AuthorizationRequestFactory $requests,
        private readonly AuthorizationPlannerInterface $planner,
        private readonly ?AuthorityRepositoryInterface $authority = null,
        private readonly bool $authorityEarlyGateEnabled = false,
    ) {}

    public function for(mixed $principal): BoundAuthorization
    {
        return new BoundAuthorization($this, $principal);
    }

    public function check(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): bool {
        return $this->decide($ability, $subject, $context, $principal)->isAllowed();
    }

    public function cannot(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): bool {
        return ! $this->check($ability, $subject, $context, $principal);
    }

    public function decide(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): DecisionResult {
        if ($this->authorityEarlyGateEnabled && $this->authority !== null) {
            $principalId = $this->extractPrincipalIdForAuthorityGate($principal);
            if ($principalId !== null) {
                $scope = $this->resolveScopeFromContext($context);
                $normalized = $this->normalizeAbilityForAuthority($ability);
                if ($normalized !== null && $this->authority->hasPermission($principalId, $normalized, $scope)) {
                    return DecisionResult::allow(
                        source: 'authorization.authority.early_gate',
                        reasonCode: 'authority_permission_granted',
                        metadata: [
                            'authority_early_gate' => true,
                            'principal_id' => $principalId,
                            'permission' => (string) $normalized,
                            'scope' => (string) $scope,
                        ],
                    );
                }
            }
        }

        $request = $this->requests->create($ability, $subject, $context, $principal);

        return $this->planner->evaluate($request);
    }

    public function authorize(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): DecisionResult {
        $result = $this->decide($ability, $subject, $context, $principal);

        if ($result->isAllowed()) {
            return $result;
        }

        if ($result->isChallenge()) {
            throw AuthorizationChallengeException::fromDecision($result);
        }

        if ($result->isFailure()) {
            throw AuthorizationEvaluationException::fromDecision($result);
        }

        throw AuthorizationDeniedException::fromDecision($result);
    }

    /**
     * Devuelve estructura explain() serializable del plan de evaluación para
     * el ability/sujeto/principal/contexto dados. Shortcut de uso rápido
     * que evita instanciar el planner a mano.
     *
     * @return array<string, mixed> Ver AuthorizationDecisionPlan::explain shape.
     */
    public function explain(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): array {
        return $this->explainPlan($ability, $subject, $context, $principal)->explain();
    }

    /**
     * Idem explain() pero devuelve el VO AuthorizationDecisionPlan con acceso
     * a métodos tipados (finalResult, stagesRaw, evaluatedAt, fingerprint).
     */
    public function explainPlan(
        string|Ability $ability,
        mixed $subject = null,
        ?AuthorizationContext $context = null,
        mixed $principal = null,
    ): AuthorizationDecisionPlan {
        if (! $this->planner instanceof \Quantum\Authorization\Core\AuthorizationPlanner) {
            $request = $this->requests->create($ability, $subject, $context, $principal);
            $flat = $this->planner->plan($request);
            $final = $this->planner->evaluate($request);
            $fp = $request->context()->attribute('authorization.metadata.fingerprint');
            $fp = is_string($fp) && trim($fp) !== '' ? $fp : null;
            $stages = [];
            if (count($flat) > 0) {
                $stages[] = ['name' => 'custom_planner', 'results' => $flat];
            }

            return new AuthorizationDecisionPlan($fp, $stages, $final, time());
        }

        $request = $this->requests->create($ability, $subject, $context, $principal);

        return $this->planner->planAsDecisionPlan($request);
    }

    private function normalizeAbilityForAuthority(string|Ability $ability): ?Permission
    {
        try {
            $name = $ability instanceof Ability ? $ability->name() : trim((string) $ability);

            return Permission::from($name);
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractPrincipalIdForAuthorityGate(mixed $principal): ?string
    {
        if ($principal === null) {
            return null;
        }

        if ($principal instanceof Principal || $principal instanceof \Quantum\Authorization\Contracts\PrincipalInterface) {
            $id = $principal->id();
            return is_string($id) && trim($id) !== '' ? $id : null;
        }

        if (is_string($principal)) {
            $trimmed = trim($principal);

            return $trimmed !== '' ? $trimmed : null;
        }

        if (is_int($principal)) {
            return (string) $principal;
        }

        if (is_object($principal)) {
            if (method_exists($principal, 'getId')) {
                $id = $principal->getId();
                if (is_string($id) || is_int($id)) {
                    $trimmed = trim((string) $id);

                    return $trimmed !== '' ? $trimmed : null;
                }
            }

            if (property_exists($principal, 'id')) {
                $id = $principal->id;
                if (is_string($id) || is_int($id)) {
                    $trimmed = trim((string) $id);

                    return $trimmed !== '' ? $trimmed : null;
                }
            }
        }

        return null;
    }

    private function resolveScopeFromContext(?AuthorizationContext $context): Scope
    {
        if ($context === null) {
            return new Scope(Scope::GLOBAL);
        }

        $scopeAttr = $context->attribute('authorization.scope');

        try {
            if ($scopeAttr instanceof Scope) {
                return $scopeAttr;
            }

            if (is_string($scopeAttr) && trim($scopeAttr) !== '') {
                return new Scope($scopeAttr);
            }
        } catch (\Throwable) {
            return new Scope(Scope::GLOBAL);
        }

        return new Scope(Scope::GLOBAL);
    }
}
