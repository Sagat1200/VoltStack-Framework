<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\AbuseProtection\RiskAssessmentResult;
use Quantum\Auth\AbuseProtection\RiskDecision;

interface RiskAdaptivePolicyInterface
{
    public function decide(RiskAssessmentResult $assessment): RiskDecision;
}
