<?php

declare(strict_types=1);

namespace Quantum\Authorization\Bridges;

use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Contracts\AuthorizationManagerInterface;
use Quantum\Authorization\Contracts\PrincipalInterface;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Core\AuthorizationRequestFactory;
use Quantum\Authorization\Decision\Decision;
use Quantum\Authorization\Decision\DecisionResult;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Principal\PrincipalType as AuthorizationPrincipalType;
use Quantum\Controllers\Security\Context\AuthenticationStrength;
use Quantum\Controllers\Security\Contracts\PrincipalInterface as SecurityPrincipalInterface;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;

/**
 * Bridge mínimo entre el subsistema Controllers/Security y el subsistema Quantum/Authorization.
 *
 * No reemplaza HardenedControllerSecurityDecisionEngine — solo ofrece un método `tryEvaluate(...)`
 * que delega a AuthorizationPlanner cuando la metadata de security trae requirements
 * de autorización declarados, y normaliza el resultado al contrato SecurityDecision.
 */
final class ControllerSecurityPlannerBridge
{
    public function __construct(
        private readonly AuthorizationManagerInterface $authorization,
        private readonly ?AuthorizationRequestFactory $requestFactory = null,
    ) {}

    /**
     * Evalúa la petición security contra el planner de Quantum/Authorization
     * cuando la metadata trae requirements de autorización.
     *
     * Retorna `null` cuando NO hay que usar este bridge (no hay requirements
     * en la metadata) y debe seguir ejecutándose el HardenedControllerSecurityDecisionEngine normal.
     *
     * Retorna `SecurityDecision` con la decisión mapeada cuando hay requirements.
     */
    public function tryEvaluate(SecurityEvaluationRequest $request): ?SecurityDecision
    {
        $authorizationRequirements = $this->extractAuthorizationRequirements($request);

        if ($authorizationRequirements === null || count($authorizationRequirements) === 0) {
            return null;
        }

        $principal = $this->resolvePrincipal($request);
        $abilityName = $this->normalizeAbilityName($request);
        $authorizationRequest = $this->buildAuthorizationRequest(
            principal: $principal,
            abilityName: $abilityName,
            subject: $request->resource,
            contextAttributes: [
                'security.metadata' => $request->metadata,
                'security.action' => $request->action,
                'authorization.metadata.public' => $this->isPublicAccess($request),
                'authorization.metadata.requirements' => $authorizationRequirements,
            ],
        );

        $result = $this->authorization->decide(
            ability: new Ability($abilityName),
            subject: $request->resource,
            context: $authorizationRequest?->context() ?? null,
            principal: $principal,
        );

        return $this->mapDecisionResultToSecurityDecision($result, $authorizationRequirements);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function extractAuthorizationRequirements(SecurityEvaluationRequest $request): ?array
    {
        $authorizationRequirements = $request->metadata['authorization_requirements'] ?? null;

        if (is_array($authorizationRequirements) && count($authorizationRequirements) > 0) {
            return array_values($authorizationRequirements);
        }

        $permissions = $request->metadata['permissions'] ?? null;

        if (is_array($permissions) && count($permissions) > 0) {
            return array_values(array_map(
                static fn (mixed $permission): array => [
                    'ability' => is_string($permission) ? $permission : (string) $permission,
                    'effect' => 'allow',
                ],
                $permissions,
            ));
        }

        return null;
    }

    private function isPublicAccess(SecurityEvaluationRequest $request): bool
    {
        $public = $request->metadata['public'] ?? $request->metadata['authorization_public'] ?? null;

        if (is_bool($public)) {
            return $public;
        }

        return false;
    }

    private function resolvePrincipal(SecurityEvaluationRequest $request): PrincipalInterface
    {
        $principal = $request->security->principal;

        if ($principal instanceof PrincipalInterface) {
            return $principal;
        }

        if ($principal instanceof SecurityPrincipalInterface) {
            $claims = method_exists($principal, 'claims') && is_array($c = $principal->claims()) ? $c : [];
            $typeRaw = method_exists($principal, 'type') ? $principal->type() : null;

            return new Principal(
                id: method_exists($principal, 'id') && is_string($id = $principal->id()) ? $id : 'anonymous',
                type: $this->mapSecurityPrincipalTypeToAuthorizationType($typeRaw),
                authenticated: (method_exists($principal, 'authenticated') && is_bool($auth = $principal->authenticated()))
                    ? $auth
                    : false,
                claims: $claims,
            );
        }

        return new Principal(
            id: 'anonymous',
            type: AuthorizationPrincipalType::Anonymous,
            authenticated: false,
        );
    }

    private function mapSecurityPrincipalTypeToAuthorizationType(mixed $type): AuthorizationPrincipalType
    {
        if ($type instanceof AuthorizationPrincipalType) {
            return $type;
        }

        if (is_string($type)) {
            $try = match (strtolower($type)) {
                'user' => AuthorizationPrincipalType::User,
                'service', 'sa' => AuthorizationPrincipalType::Service,
                'api', 'apiclient', 'api_client' => AuthorizationPrincipalType::ApiClient,
                'system', 'internal' => AuthorizationPrincipalType::System,
                'impersonated', 'impersonated_user' => AuthorizationPrincipalType::ImpersonatedUser,
                'anonymous', '', 'anon', 'guest' => AuthorizationPrincipalType::Anonymous,
                default => null,
            };
            if ($try !== null) {
                return $try;
            }
        }

        if (is_object($type) && method_exists($type, 'value') && is_string($v = $type->value())) {
            try {
                return $this->mapSecurityPrincipalTypeToAuthorizationType($v);
            } catch (\Throwable) {
                // fallthrough
            }
        }

        if (is_object($type) && property_exists($type, 'value')) {
            try {
                return $this->mapSecurityPrincipalTypeToAuthorizationType((string) $type->value);
            } catch (\Throwable) {
                // fallthrough
            }
        }

        return AuthorizationPrincipalType::Anonymous;
    }

    private function normalizeAbilityName(SecurityEvaluationRequest $request): string
    {
        $explicit = $request->metadata['authorization_ability'] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $resourceName = $this->resourceName($request->resource);

        $action = strtolower($request->action);
        if ($resourceName !== '') {
            return $resourceName . ':' . $action;
        }

        return $action !== '' ? $action : 'security:invoke';
    }

    private function resourceName(mixed $resource): string
    {
        if (is_object($resource)) {
            $basename = (new \ReflectionClass($resource))->getShortName();

            return strtolower((string) preg_replace('/(?<=\\w)(?=[A-Z])/', '_', $basename));
        }

        if (is_string($resource) && $resource !== '') {
            $basename = basename(str_replace('\\', '/', $resource));

            return strtolower($basename);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $contextAttributes
     */
    private function buildAuthorizationRequest(
        PrincipalInterface $principal,
        string $abilityName,
        mixed $subject,
        array $contextAttributes,
    ): ?AuthorizationRequest {
        if ($this->requestFactory === null) {
            return null;
        }

        try {
            return ($this->requestFactory)(
                principal: $principal,
                ability: new Ability($abilityName),
                subject: $subject,
                attributes: $contextAttributes,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $requirements
     */
    private function mapDecisionResultToSecurityDecision(
        DecisionResult $result,
        array $requirements,
    ): SecurityDecision {
        $reasonCode = $result->reasonCode() !== '' ? $result->reasonCode() : 'authorization_planner_default';
        $fingerprint = $result->metadataFingerprint();
        $obligations = array_merge(
            $result->metadata(),
            [
                'requirements' => $requirements,
            ],
            $fingerprint !== null && $fingerprint !== ''
                ? ['authorization.metadata.fingerprint' => $fingerprint]
                : [],
        );

        return match ($result->decision()) {
            Decision::Allow => SecurityDecision::allow(
                policyId: 'authorization.planner',
                reasonCode: $reasonCode,
                obligations: $obligations,
            ),
            Decision::Abstain => SecurityDecision::abstain(
                policyId: 'authorization.planner',
                reasonCode: $reasonCode,
                obligations: $obligations,
            ),
            Decision::Deny => SecurityDecision::deny(
                policyId: 'authorization.planner',
                reasonCode: $reasonCode,
                obligations: $obligations,
            ),
            Decision::Challenge => SecurityDecision::challenge(
                policyId: 'authorization.planner',
                reasonCode: $reasonCode,
                obligations: array_merge($obligations, [
                    'required_strength_value' => AuthenticationStrength::Password->value,
                ]),
            ),
        };
    }
}
