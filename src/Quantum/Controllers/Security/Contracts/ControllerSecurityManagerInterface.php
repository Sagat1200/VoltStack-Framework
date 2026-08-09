<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Contracts;

use Quantum\Http\Request;
use Quantum\Controllers\ControllerExecutionContext;
use Quantum\Controllers\Security\Context\ControllerSecurityContext;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;

/**
 * @api Contrato público estable del Security Manager global (Bloques 11-16).
 *
 * Inicializa el contexto de seguridad, evalúa policies y autoriza/deniega requests.
 * Implementación por defecto: `ControllerSecurityManager` (sandbox wrapper
 * `HardenedControllerSecurityDecisionEngine`). Firmas congeladas hasta 2.x.
 */
interface ControllerSecurityManagerInterface
{
    public function initialize(
        Request $request,
        ControllerExecutionContext $execution,
    ): ControllerSecurityContext;

    public function authorize(SecurityEvaluationRequest $request): SecurityDecision;

    public function finalize(ControllerSecurityContext $context): void;
}
