<?php

declare(strict_types=1);

namespace Quantum\Authorization\Core\Stages;

use Quantum\Authorization\Contracts\AuthorizationEvaluationStageInterface;
use Quantum\Authorization\Contracts\RateLimitStoreInterface;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Decision\DecisionResult;

final readonly class AdaptiveAccessStage implements AuthorizationEvaluationStageInterface
{
    /**
     * @param list<string> $scoreAttributeKeys
     * @param list<string> $levelAttributeKeys
     * @param list<string> $availableMethods
     * @param array<string, array{step_up_threshold?:int|null,deny_threshold?:int|null,allow_threshold?:int|null,step_up_reason_code?:string,deny_reason_code?:string}>|null $policies
     * @param array{enabled?:bool,max_requests?:int,window_seconds?:int,by?:string}|null $rateLimitConfig
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
        private ?array $policies = null,
        private ?int $allowThreshold = null,
        private ?AdaptiveAccessRuntimeConfig $runtimeConfig = null,
        private ?RateLimitStoreInterface $rateLimitStore = null,
        private ?array $rateLimitConfig = null,
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

        $rateLimitDecision = $this->evaluateRateLimit($request);
        if ($rateLimitDecision !== null) {
            return [$rateLimitDecision];
        }

        $context = $request->context();
        $attributes = $context->attributes();

        $multiFactorDecision = $this->evaluateMultiFactor($attributes);
        if ($multiFactorDecision !== null) {
            return [$multiFactorDecision];
        }

        $riskScore = $this->extractInt($attributes, $this->scoreAttributeKeys);

        if ($riskScore === null) {
            return [];
        }

        $policy = $this->resolvePolicy($request, $attributes);

        $denyThreshold = $policy['deny_threshold'] ?? $this->denyThreshold;
        $stepUpThreshold = $policy['step_up_threshold'] ?? $this->stepUpThreshold;
        $allowThreshold = $policy['allow_threshold'] ?? $this->allowThreshold;
        $denyReasonCode = $policy['deny_reason_code'] ?? $this->denyReasonCode;
        $stepUpReasonCode = $policy['step_up_reason_code'] ?? $this->stepUpReasonCode;
        $matchedDimension = $policy['_dimension'] ?? null;

        // Runtime overrides (via authz:adaptive:tune --apply) take precedence over policy/config.
        if ($this->runtimeConfig !== null) {
            if ($this->runtimeConfig->denyThreshold() !== null) {
                $denyThreshold = $this->runtimeConfig->denyThreshold();
            }
            if ($this->runtimeConfig->stepUpThreshold() !== null) {
                $stepUpThreshold = $this->runtimeConfig->stepUpThreshold();
            }
            if ($this->runtimeConfig->allowThreshold() !== null) {
                $allowThreshold = $this->runtimeConfig->allowThreshold();
            }
        }

        $riskLevel = $this->extractString($attributes, $this->levelAttributeKeys);
        $baseMetadata = array_filter([
            'risk_score' => $riskScore,
            'risk_level' => $riskLevel,
            'adaptive_policy_dimension' => $matchedDimension,
        ], static fn (mixed $value): bool => $value !== null);

        if ($allowThreshold !== null && $riskScore <= $allowThreshold) {
            return [
                DecisionResult::allow(
                    source: 'authorization.stage:adaptive_access',
                    reasonCode: 'auth.risk_allow',
                    metadata: $baseMetadata + [
                        'adaptive_access_action' => 'allow',
                        'risk_allow_threshold' => $allowThreshold,
                    ],
                ),
            ];
        }

        if ($denyThreshold !== null && $riskScore >= $denyThreshold) {
            return [
                DecisionResult::deny(
                    source: 'authorization.stage:adaptive_access',
                    reasonCode: $denyReasonCode,
                    metadata: $baseMetadata + [
                        'adaptive_access_action' => 'deny',
                        'risk_deny_threshold' => $denyThreshold,
                    ],
                ),
            ];
        }

        if ($stepUpThreshold !== null && $riskScore >= $stepUpThreshold) {
            return [
                DecisionResult::challenge(
                    source: 'authorization.stage:adaptive_access',
                    reasonCode: $stepUpReasonCode,
                    metadata: array_filter($baseMetadata + [
                        'adaptive_access_action' => 'step_up',
                        'risk_step_up_threshold' => $stepUpThreshold,
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
     * Evalua rate-limit por principal. Retorna un DecisionResult de denegacion
     * si el contador excede el maximo; null si no aplica.
     */
    private function evaluateRateLimit(AuthorizationRequest $request): ?DecisionResult
    {
        $config = is_array($this->rateLimitConfig) ? $this->rateLimitConfig : [];

        if (! ($config['enabled'] ?? false) || $this->rateLimitStore === null) {
            return null;
        }

        $maxRequests = (int) ($config['max_requests'] ?? 60);
        $windowSeconds = (int) ($config['window_seconds'] ?? 60);

        try {
            $principalId = $request->principal()->id();
        } catch (\Throwable) {
            return null;
        }

        if ($principalId === '') {
            return null;
        }

        $key = 'rl:' . $principalId;
        $count = $this->rateLimitStore->increment($key, $windowSeconds);

        if ($count > $maxRequests) {
            return DecisionResult::deny(
                source: 'authorization.stage:adaptive_access',
                reasonCode: 'auth.rate_limit_exceeded',
                metadata: [
                    'adaptive_access_action' => 'rate_limit',
                    'rate_limit_count' => $count,
                    'rate_limit_window' => $windowSeconds,
                    'rate_limit_max' => $maxRequests,
                ],
            );
        }

        return null;
    }

    /**
     * Evalua step-up multi-factor. Si el contexto define required_factors y
     * no todos estan completados, retorna un challenge indicando el siguiente
     * factor requerido. Null si no aplica multi-factor o todos completados.
     *
     * @param array<string, mixed> $attributes
     */
    private function evaluateMultiFactor(array $attributes): ?DecisionResult
    {
        $required = $attributes['authorization.step_up.required_factors'] ?? $attributes['step_up_required_factors'] ?? null;

        if (! is_array($required) || $required === []) {
            return null;
        }

        $completed = $attributes['authorization.step_up.completed_factors'] ?? $attributes['step_up_completed_factors'] ?? [];
        if (! is_array($completed)) {
            $completed = [];
        }

        $completedSet = array_map('strval', $completed);

        $nextFactor = null;
        foreach ($required as $factor) {
            $factorStr = (string) $factor;
            if (! in_array($factorStr, $completedSet, true)) {
                $nextFactor = $factorStr;
                break;
            }
        }

        if ($nextFactor === null) {
            // Todos los factores completados: no hay step-up multi-factor.
            return null;
        }

        return DecisionResult::challenge(
            source: 'authorization.stage:adaptive_access',
            reasonCode: 'auth.step_up_required',
            metadata: [
                'adaptive_access_action' => 'step_up',
                'next_factor' => $nextFactor,
                'completed_factors' => $completedSet,
                'required_factors' => array_map('strval', $required),
                'current_strength' => count($completedSet),
            ],
        );
    }

    /**
     * Resuelve la política aplicable por dimensión (específico → general).
     *
     * Orden: operation:<name> → canal:<canal> → tenant:<tenant> → global.
     * La política global se representa por la configuración del constructor
     * (no requiere entrada en $this->policies).
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function resolvePolicy(AuthorizationRequest $request, array $attributes): array
    {
        $policies = is_array($this->policies) ? $this->policies : [];

        $operation = $this->extractOperation($request, $attributes);
        if ($operation !== null) {
            $key = 'operation:' . $operation;
            if (isset($policies[$key]) && is_array($policies[$key])) {
                return $policies[$key] + ['_dimension' => $key];
            }
        }

        $canal = $this->extractCanal($attributes);
        if ($canal !== null) {
            $key = 'canal:' . $canal;
            if (isset($policies[$key]) && is_array($policies[$key])) {
                return $policies[$key] + ['_dimension' => $key];
            }
        }

        $tenant = $this->extractTenant($attributes);
        if ($tenant !== null) {
            $key = 'tenant:' . $tenant;
            if (isset($policies[$key]) && is_array($policies[$key])) {
                return $policies[$key] + ['_dimension' => $key];
            }
        }

        return ['_dimension' => 'global'];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function extractOperation(AuthorizationRequest $request, array $attributes): ?string
    {
        try {
            $name = $request->ability()->name();
            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        } catch (\Throwable) {
            // Fall through to attribute lookup.
        }

        return $this->extractString($attributes, ['authorization.operation', 'operation', 'ability']);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function extractCanal(array $attributes): ?string
    {
        return $this->extractString($attributes, ['authorization.canal', 'canal', 'channel']);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function extractTenant(array $attributes): ?string
    {
        return $this->extractString($attributes, ['authorization.tenant', 'tenant.id', 'tenant_id']);
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
