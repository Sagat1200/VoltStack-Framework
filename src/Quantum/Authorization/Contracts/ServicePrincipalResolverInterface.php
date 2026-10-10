<?php

declare(strict_types=1);

namespace Quantum\Authorization\Contracts;

use Quantum\Http\Request;
use VoltStack\Runtime\Context\RuntimeContext;

interface ServicePrincipalResolverInterface
{
    /**
     * Debe devolver null cuando no hay suficiente informacion para resolver
     * una identidad de servicio (fail-closed hacia el resolver anónimo default).
     *
     * Cuando lo resuelve, el claims del Principal debe incluir las claves:
     *   - resolved_via: 'runtime_metadata' | 'cli_flag' | 'service_map'
     */
    public function resolve(?RuntimeContext $runtimeContext = null, ?Request $request = null): ?PrincipalInterface;
}
