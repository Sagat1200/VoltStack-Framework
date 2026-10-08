<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Budget;

final readonly class RuntimeBudgetCalibrationArtifact
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private string $generationId,
        private string $driver,
        private string $profile,
        private string $fingerprint,
        private string $manifestPath,
        private array $payload,
        private int $createdAt,
    ) {
    }

    public function generationId(): string
    {
        return $this->generationId;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function profile(): string
    {
        return $this->profile;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function manifestPath(): string
    {
        return $this->manifestPath;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function createdAt(): int
    {
        return $this->createdAt;
    }

    public function recommendedBudget(): RuntimeBudgetBaseline
    {
        $payload = $this->payload['report']['recommended_budget'] ?? [];

        return new RuntimeBudgetBaseline(
            driver: $this->driver,
            totalMaximumMs: isset($payload['total_budget_ms']) && is_numeric($payload['total_budget_ms'])
                ? (float) $payload['total_budget_ms']
                : null,
            requestMaximumMs: isset($payload['request_budget_ms']) && is_numeric($payload['request_budget_ms'])
                ? (float) $payload['request_budget_ms']
                : null,
            source: 'published-calibration',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generation_id' => $this->generationId,
            'driver' => $this->driver,
            'profile' => $this->profile,
            'fingerprint' => $this->fingerprint,
            'manifest_path' => $this->manifestPath,
            'created_at' => $this->createdAt,
            'recommended_budget' => $this->recommendedBudget()->toArray(),
        ];
    }
}
