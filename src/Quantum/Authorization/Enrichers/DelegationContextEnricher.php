<?php

declare(strict_types=1);

namespace Quantum\Authorization\Enrichers;

use Quantum\Authorization\Contracts\AuthorizationRequestEnricherInterface;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Principal\PrincipalType;

/**
 * Enricher que proyecta informacion de impersonation, delegation y service
 * principal al AuthorizationContext para que policies, gates, ABAC conditions,
 * Adaptive Access Stage y explain plan puedan consumirla de forma estable.
 *
 * Es opt-in: solo se registra cuando delegation.enabled=true o
 * service_principal_resolver.enabled=true. Si ambos flags estan apagados
 * el enricher no afecta el pipeline (no se registra).
 */
final readonly class DelegationContextEnricher implements AuthorizationRequestEnricherInterface
{
    public function enrich(AuthorizationRequest $request): AuthorizationRequest
    {
        $context = $request->context();
        $principal = $request->principal();
        $attributes = $context->attributes();
        $claims = $principal->claims();

        // Proyeccion impersonation / delegation
        if ($principal->type() === PrincipalType::ImpersonatedUser) {
            $originator = is_string($claims['originator_principal_id'] ?? null)
                ? $claims['originator_principal_id']
                : ($context->attribute('authorization.impersonation.originator_id'));
            $target = is_string($claims['target_principal_id'] ?? null)
                ? $claims['target_principal_id']
                : ($context->attribute('authorization.impersonation.target_id') ?? $principal->id());
            $scope = $claims['impersonation_scope'] ?? $context->attribute('authorization.impersonation.scope');
            $impersonatedAt = $claims['impersonated_at'] ?? $context->attribute('authorization.impersonation.impersonated_at');

            $attributes['authorization.originator.principal_id'] = is_string($originator) && $originator !== '' ? $originator : null;
            $attributes['authorization.target.principal_id'] = is_string($target) && $target !== '' ? $target : null;
            $attributes['authorization.impersonation.scope'] = is_string($scope) && $scope !== '' ? $scope : null;
            $attributes['authorization.impersonation.principal_type'] = PrincipalType::ImpersonatedUser->value;
            $attributes['authorization.impersonation.impersonated_at'] = is_string($impersonatedAt) && $impersonatedAt !== '' ? $impersonatedAt : null;
            $attributes['authorization.delegation.evaluation_hint'] = 'impersonation_check_trustee_grantor_pairs';
        }

        // Proyeccion service principal
        if ($principal->type() === PrincipalType::Service || $principal->type() === PrincipalType::ApiClient) {
            $resolvedVia = is_string($claims['resolved_via'] ?? null) ? $claims['resolved_via'] : 'unknown';
            $servicePrincipalId = is_string($claims['service_principal_id'] ?? null) ? $claims['service_principal_id'] : $principal->id();
            $attributes['authorization.service.principal_id'] = $servicePrincipalId;
            $attributes['authorization.service.type'] = $principal->type()->value;
            $attributes['authorization.service.resolved_via'] = $resolvedVia;
        }

        $attributes = array_filter($attributes, static fn (mixed $v): bool => $v !== null);

        return $request->withContext($context->withAttributes($attributes));
    }
}
