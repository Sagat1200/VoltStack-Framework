<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Contracts;

use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityDecisionKey;

interface SecurityDecisionCacheInterface
{
    public function get(SecurityDecisionKey $key): ?SecurityDecision;

    public function put(SecurityDecisionKey $key, SecurityDecision $decision): void;

    public function clear(): int;
}
