<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Contracts;

use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;

/**
 * @api Contrato público estable del Decision Engine (base o hardened sandbox).
 *
 * Implementaciones disponibles:
 *   - `ControllerSecurityDecisionEngine` (base, sin aislamiento extra)
 *   - `HardenedControllerSecurityDecisionEngine` (sandbox + budget enforcement)
 */
interface ControllerSecurityDecisionEngineInterface
{
    public function decide(SecurityEvaluationRequest $request): SecurityDecision;
}
