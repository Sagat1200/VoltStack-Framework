<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

use Quantum\Auth\Identity\IdentityInterface;

interface AuthenticationPolicyRuleInterface
{
    public function applies(
        string $operation,
        ?IdentityInterface $identity = null,
        array $context = [],
    ): bool;

    public function evaluate(
        string $operation,
        ?IdentityInterface $identity = null,
        array $context = [],
    ): PolicyDecision;
}
