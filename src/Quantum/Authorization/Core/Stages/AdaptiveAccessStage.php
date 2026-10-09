<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core\Stages;

use Quantum\Authorization\Contracts\AuthorizationEvaluationStageInterface;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Decision\DecisionResult;

final readonly class AdaptiveAccessStage implements AuthorizationEvaluationStageInterface
{
    /**
     * @param list<string> $scoreAttributeKeys
     * @param list<string> $levelAttributeKeys
     * @param list<string> $availableMethods
     */
    public function __construct(
        private bool $enabled = false,
        private ?int $stepUpThreshold = null,
        private ?int $denyThreshold = null,
        private array $scoreAttributeKeys = ['auth_risk_score', 'risk_score', 'risk.score'],
        private array $levelAttributeKeys = ['auth_risk_level', 'risk_level', 'risk.level'],
        private string $stepUpReasonCode = 'auth.step_up_required',
        private string $denyReasonCode = 'auth.risk_denied',
        private array $availableMethods = [],
        private ?string $challengeEndpoint = null,
        private ?string $continuationEndpoint = null,
        private ?string $requiredStrengthName = null,
        private ?int $requiredStrengthValue = null,
    ) {}

    public function name(): string
    {
        return 'adaptive_access';
    }

    public function evaluate(AuthorizationRequest $request): array
    {
        if (! $this->enabled) {
            return [];
        }

        $context = $request->context();
        $riskScore = $this->extractInt($context->attributes(), $this->scoreAttributeKeys);

        if ($riskScore === null) {
            return [];
        }

        $riskLevel = $this->extractString($context->attributes(), $this->levelAttributeKeys);
        $baseMetadata = array_filter([
            'risk_score' => $riskScore,
            'risk_level' => $riskLevel,
        ], static fn (mixed $value): bool => $value !== null);

        if ($this->denyThreshold !== null && $riskScore >= $this->denyThreshold) {
            return [
                DecisionResult::deny(
                    source: 'authorization.stage:adaptive_access',
                    reasonCode: $this->denyReasonCode,
                    metadata: $baseMetadata + [
                        'adaptive_access_action' => 'deny',
                        'risk_deny_threshold' => $this->denyThreshold,
                    ],
                ),
            ];
        }

        if ($this->stepUpThreshold !== null && $riskScore >= $this->stepUpThreshold) {
            return [
                DecisionResult::challenge(
                    source: 'authorization.stage:adaptive_access',
                    reasonCode: $this->stepUpReasonCode,
                    metadata: array_filter($baseMetadata + [
                        'adaptive_access_action' => 'step_up',
                        'risk_step_up_threshold' => $this->stepUpThreshold,
                        'step_up_available_methods' => $this->availableMethods,
                        'step_up_challenge_endpoint' => $this->challengeEndpoint,
                        'step_up_continuation_endpoint' => $this->continuationEndpoint,
                        'required_strength_name' => $this->requiredStrengthName,
                        'required_strength_value' => $this->requiredStrengthValue,
                    ], static fn (mixed $value): bool => $value !== null),
                ),
            ];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $keys
     */
    private function extractInt(array $attributes, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $this->extractValue($attributes, $key);

            if (is_int($value)) {
                return $value;
            }

            if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
                return (int) trim($value);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param list<string> $keys
     */
    private function extractString(array $attributes, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->extractValue($attributes, $key);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function extractValue(array $attributes, string $key): mixed
    {
        if (array_key_exists($key, $attributes)) {
            return $attributes[$key];
        }

        if (! str_contains($key, '.')) {
            return null;
        }

        $current = $attributes;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
