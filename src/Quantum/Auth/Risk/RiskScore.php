<?php

declare(strict_types=1);

namespace Quantum\Auth\Risk;

final readonly class RiskScore
{
    public const LEVEL_LOW = 'low';
    public const LEVEL_MEDIUM = 'medium';
    public const LEVEL_HIGH = 'high';
    public const LEVEL_CRITICAL = 'critical';

    /**
     * @param list<string> $reasonCodes
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public int $score,
        public string $level,
        public array $reasonCodes = [],
        public array $metadata = [],
    ) {
    }

    /**
     * @param list<string> $reasonCodes
     * @param array<string, mixed> $metadata
     */
    public static function fromScore(int $rawScore, array $reasonCodes = [], array $metadata = []): self
    {
        $normalized = max(0, min(100, $rawScore));
        $level = match (true) {
            $normalized >= 80 => self::LEVEL_CRITICAL,
            $normalized >= 55 => self::LEVEL_HIGH,
            $normalized >= 30 => self::LEVEL_MEDIUM,
            default => self::LEVEL_LOW,
        };

        return new self(
            score: $normalized,
            level: $level,
            reasonCodes: $reasonCodes,
            metadata: $metadata,
        );
    }
}
