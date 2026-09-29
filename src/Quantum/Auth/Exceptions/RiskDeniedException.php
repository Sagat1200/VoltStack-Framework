<?php

declare(strict_types=1);

namespace Quantum\Auth\Exceptions;

class RiskDeniedException extends AuthenticationException
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $message = 'Authentication blocked by adaptive risk policy.',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly int $riskScore = 0,
        public readonly int $denyThreshold = 95,
        public readonly array $metadata = [],
    ) {
        parent::__construct($message, 'auth.risk_denied');
    }
}
