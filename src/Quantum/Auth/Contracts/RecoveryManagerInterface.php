<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Recovery\RecoveryContinuationRequest;
use Quantum\Auth\Recovery\RecoveryRequest;
use Quantum\Auth\Recovery\RecoveryResult;
use Quantum\Auth\Recovery\RecoveryStartResult;

interface RecoveryManagerInterface
{
    public function begin(RecoveryRequest $request): RecoveryStartResult;

    public function continueRecovery(RecoveryContinuationRequest $request): RecoveryResult;
}
