<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\AbuseProtection\RiskDecision;
use Quantum\Auth\AbuseProtection\RiskAssessmentResult;

/**
 * @internal V2 AdaptiveRiskPolicy para DV-AUTH-083.
 *           decide() recibe el assessment y retorna RiskDecision allow / step_up_required / denied.
 */
interface AdaptiveRiskPolicyInterface extends RiskAdaptivePolicyInterface
{
}
