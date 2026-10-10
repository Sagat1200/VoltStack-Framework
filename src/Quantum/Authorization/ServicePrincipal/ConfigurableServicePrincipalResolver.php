<?php

declare(strict_types=1);

namespace Quantum\Authorization\ServicePrincipal;

use Quantum\Authorization\Contracts\PrincipalInterface;
use Quantum\Authorization\Contracts\ServicePrincipalResolverInterface;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Principal\PrincipalType;
use Quantum\Http\Request;
use VoltStack\Runtime\Context\RuntimeContext;

/**
 * Resolver built-in opt-in para superficies sin capa Auth HTTP.
 *
 * Orden de resolucion (fail-closed a null si nada aplica cada paso si algo matchea):
 *   1) --as-service=<id> (cuando Request contiene option flag via attributes en runtime/ENV global con clave service_principal_as).
 *   2) RuntimeContext->metadata() service_principal.id y claims
 *   3) Config map authorization.service_principals.map.<id> cuando llega un id desde 1 o 2 pero no hay claims.
 *
 * Si no hay suficiente info, devuelve null para que el resolver principal default
 * produzca AnonymousPrincipal (fail-closed por defecto).
 */
final class ConfigurableServicePrincipalResolver implements ServicePrincipalResolverInterface
{
    /**
     * @param array{map?:array<string,array{type?:string,claims?:array<string,mixed>}>} $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function resolve(?RuntimeContext $runtimeContext = null, ?Request $request = null): ?PrincipalInterface
    {
        $explicitId = null;
        $resolvedVia = null;

        // Paso 1: flag via runtime metadata as-service (en CLI se inyecta RuntimeContext con este valor, o bien desde el request attributes).
        $runtimeMetadata = $runtimeContext !== null ? $runtimeContext->metadata() : [];
        $asService = $runtimeMetadata['service_principal.as'] ?? $runtimeMetadata['as_service'] ?? null;

        if (is_string($asService) && trim($asService) !== '') {
            $explicitId = trim($asService);
            $resolvedVia = 'runtime_flag';
        }

        if ($explicitId === null && $request !== null) {
            $requestAttrService = $request->attribute('service_principal.as') ?? $request->queryParam('as-service') ?? $request->server('VOLT_AS_SERVICE');
            if (is_string($requestAttrService) && trim($requestAttrService) !== '') {
                $explicitId = trim($requestAttrService);
                $resolvedVia = 'request_attribute';
            }
        }

        // Paso 2: Runtime metadata service_principal.id
        $runtimePrincipalIdRaw = $runtimeMetadata['service_principal.id'] ?? null;
        $runtimePrincipalId = is_string($runtimePrincipalIdRaw) && trim($runtimePrincipalIdRaw) !== ''
            ? trim($runtimePrincipalIdRaw)
            : null;

        if ($explicitId === null && $runtimePrincipalId !== null) {
            $explicitId = $runtimePrincipalId;
            $resolvedVia = 'runtime_metadata';
        }

        if ($explicitId === null) {
            return null;
        }

        // Tipo preferencial
        $typeValue = $runtimeMetadata['service_principal.type'] ?? null;
        $type = PrincipalType::tryFrom(is_string($typeValue) ? $typeValue : '') ?? PrincipalType::Service;

        // Claims base desde runtime metadata
        $baseClaims = $runtimeMetadata['service_principal.claims'] ?? [];
        $baseClaims = is_array($baseClaims) ? $baseClaims : [];

        // Aplico claims desde config map
        $map = is_array($this->config['map'] ?? null) ? $this->config['map'] : [];
        $entry = is_array($map[$explicitId] ?? null) ? $map[$explicitId] : [];

        $configTypeRaw = $entry['type'] ?? null;
        $configType = is_string($configTypeRaw) ? PrincipalType::tryFrom($configTypeRaw) : null;
        if ($configType instanceof PrincipalType) {
            $type = $configType;
        }

        $configClaims = is_array($entry['claims'] ?? null) ? $entry['claims'] : [];

        $finalClaims = array_replace($configClaims, $baseClaims, [
            'resolved_via' => $resolvedVia ?? 'service_map',
            'service_principal_id' => $explicitId,
        ]);

        return new Principal(
            id: $explicitId,
            type: $type,
            authenticated: true,
            claims: $finalClaims,
        );
    }
}
