<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Context\AuthenticationRequest;
use Quantum\Auth\Risk\RiskScore;

interface RiskSignalProviderInterface
{
    public function evaluate(AuthenticationRequest $request, ?AuthenticationContext $context = null): RiskScore;
}
