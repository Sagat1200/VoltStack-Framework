<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Contracts;

use Quantum\Exceptions\Context\RecoveryContext;
use Quantum\Exceptions\Model\ExceptionDescriptor;
use Quantum\Exceptions\Model\RecoveryDecision;

interface RecoveryPolicyInterface
{
    public function decide(ExceptionDescriptor $error, RecoveryContext $context): RecoveryDecision;
}
