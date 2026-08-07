<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Contracts;

use Quantum\Http\Request;
use Quantum\Controllers\ControllerExecutionContext;
use Quantum\Controllers\Security\Context\ControllerSecurityContext;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;

interface ControllerSecurityManagerInterface
{
    public function initialize(
        Request $request,
        ControllerExecutionContext $execution,
    ): ControllerSecurityContext;

    public function authorize(SecurityEvaluationRequest $request): SecurityDecision;

    public function finalize(ControllerSecurityContext $context): void;
}
