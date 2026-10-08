<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

use Quantum\Controllers\Security\Context\AuthenticationStrength;
use RuntimeException;

final class StepUpRequiredException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode = 'auth.step_up_required',
        public readonly AuthenticationStrength $requiredStrength = AuthenticationStrength::MultiFactor,
        public readonly AuthenticationStrength $currentStrength = AuthenticationStrength::Password,
        string $message = 'Step-up authentication is required for this resource.',
        public readonly ?string $operation = null,
        public readonly ?int $riskScore = null,
        public readonly ?string $riskLevel = null,
        public readonly ?int $requiredMinAssurance = null,
        public readonly ?int $currentAssurance = null,
        public readonly ?string $challengeEndpoint = null,
        public readonly ?string $continuationEndpoint = null,
        /** @var list<string> */
        public readonly array $availableMethods = [],
    ) {
        parent::__construct($message);
    }
}
