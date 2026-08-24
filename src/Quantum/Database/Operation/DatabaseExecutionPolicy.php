<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

final readonly class DatabaseExecutionPolicy
{
    public function __construct(
        public int $timeoutMs = 30000,
        public int $maxRows = 100000,
        public int $maxDepth = 32,
        public int $retryLimit = 1,
        public int $retryBackoffMs = 10,
        public int $circuitFailureThreshold = 3,
        public int $circuitCooldownMs = 30000,
        public int $slowQueryThresholdMs = 250,
        public bool $audit = true,
        public bool $strict = true,
        public bool $redactBindings = true,
    ) {}

    /**
     * @param array<string, mixed> $databaseConfig
     */
    public static function fromConfig(array $databaseConfig): self
    {
        $timeouts = is_array($databaseConfig['timeouts'] ?? null) ? $databaseConfig['timeouts'] : [];
        $limits = is_array($databaseConfig['query_limits'] ?? null) ? $databaseConfig['query_limits'] : [];
        $observability = is_array($databaseConfig['observability'] ?? null) ? $databaseConfig['observability'] : [];
        $resilience = is_array($databaseConfig['resilience'] ?? null) ? $databaseConfig['resilience'] : [];
        $circuit = is_array($resilience['circuit_breaker'] ?? null) ? $resilience['circuit_breaker'] : [];
        $security = is_array($databaseConfig['security'] ?? null) ? $databaseConfig['security'] : [];

        return new self(
            timeoutMs: max(1, (int) ($timeouts['soft_timeout_ms'] ?? 30000)),
            maxRows: max(1, (int) ($limits['max_rows'] ?? 100000)),
            maxDepth: max(1, (int) ($limits['max_depth'] ?? 32)),
            retryLimit: max(0, (int) ($resilience['retry_limit'] ?? 1)),
            retryBackoffMs: max(0, (int) ($resilience['retry_backoff_ms'] ?? 10)),
            circuitFailureThreshold: max(1, (int) ($circuit['failure_threshold'] ?? 3)),
            circuitCooldownMs: max(1, (int) ($circuit['cooldown_ms'] ?? 30000)),
            slowQueryThresholdMs: max(1, (int) ($observability['slow_query_ms'] ?? 250)),
            audit: (bool) ($observability['audit'] ?? true),
            strict: (bool) ($databaseConfig['strict'] ?? true),
            redactBindings: (bool) ($security['redact_sensitive'] ?? true),
        );
    }

    public function withTimeoutMs(int $timeoutMs): self
    {
        return new self(
            timeoutMs: max(1, $timeoutMs),
            maxRows: $this->maxRows,
            maxDepth: $this->maxDepth,
            retryLimit: $this->retryLimit,
            retryBackoffMs: $this->retryBackoffMs,
            circuitFailureThreshold: $this->circuitFailureThreshold,
            circuitCooldownMs: $this->circuitCooldownMs,
            slowQueryThresholdMs: $this->slowQueryThresholdMs,
            audit: $this->audit,
            strict: $this->strict,
            redactBindings: $this->redactBindings,
        );
    }

    public function withMaxRows(int $maxRows): self
    {
        return new self(
            timeoutMs: $this->timeoutMs,
            maxRows: max(1, $maxRows),
            maxDepth: $this->maxDepth,
            retryLimit: $this->retryLimit,
            retryBackoffMs: $this->retryBackoffMs,
            circuitFailureThreshold: $this->circuitFailureThreshold,
            circuitCooldownMs: $this->circuitCooldownMs,
            slowQueryThresholdMs: $this->slowQueryThresholdMs,
            audit: $this->audit,
            strict: $this->strict,
            redactBindings: $this->redactBindings,
        );
    }
}
