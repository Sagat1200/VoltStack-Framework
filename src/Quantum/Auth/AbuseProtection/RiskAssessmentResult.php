<?php

declare(strict_types=1);

namespace Quantum\Auth\AbuseProtection;

/**
 * @internal V2 Risk Assessment con adaptive policy.
 *           riskScore 0 (nada sospechoso) -> 100 (ataque confirmado).
 */
final readonly class RiskAssessmentResult
{
    /**
     * @param array<string, mixed> $riskFactors
     */
    public function __construct(
        public int $riskScore,
        public int $assessedAt,
        public array $riskFactors = [],
        public ?string $engineVersion = 'v2_adaptive_083',
    ) {
    }

    public static function allow(int $now, array $factors = []): self
    {
        return new self(riskScore: 0, assessedAt: $now, riskFactors: $factors);
    }

    public static function ofScore(int $score, int $now, array $factors = []): self
    {
        $clamped = max(0, min(100, $score));
        return new self(riskScore: $clamped, assessedAt: $now, riskFactors: $factors);
    }
}
