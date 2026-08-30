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
        public bool $retryMutationsWhenIdempotent = false,
        public int $idempotencyPendingTtlSeconds = 300,
        public string $legacyReplayMode = 'allow',
        public string $remoteReplayAttestationMode = 'allow',
        public int $remoteReplayAttestationMaxAgeSeconds = 0,
        public string $remoteReplayValidationMode = 'allow',
        public int $remoteReplayValidationReceiptMaxAgeSeconds = 0,
        public string $remoteReplayValidationReceiptReuseScope = 'current_node',
        public array $remoteReplayValidationReceiptTrustedNodes = [],
        public int $remoteReplayValidationReceiptPropagationMaxAgeSeconds = 0,
        public int $remoteReplayValidationReceiptPropagationHealthLimit = 250,
        public array $remoteReplayValidationReceiptPropagationTrustedNodes = [],
        public int $circuitFailureThreshold = 3,
        public int $circuitCooldownMs = 30000,
        public int $slowQueryThresholdMs = 250,
        public bool $fallbackEnabled = false,
        public string $fallbackMode = 'off',
        public int $fallbackAggregateLimit = 50,
        public int $fallbackOpenSegmentsThreshold = 1,
        public int $fallbackHalfOpenSegmentsThreshold = 1,
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
        $idempotency = is_array($databaseConfig['idempotency'] ?? null) ? $databaseConfig['idempotency'] : [];
        $circuit = is_array($resilience['circuit_breaker'] ?? null) ? $resilience['circuit_breaker'] : [];
        $fallback = is_array($resilience['fallback'] ?? null) ? $resilience['fallback'] : [];
        $security = is_array($databaseConfig['security'] ?? null) ? $databaseConfig['security'] : [];
        $legacyReplayMode = strtolower((string) ($idempotency['legacy_replay_mode'] ?? 'allow'));
        if (!in_array($legacyReplayMode, ['allow', 'warn', 'block'], true)) {
            $legacyReplayMode = 'allow';
        }
        $remoteReplayAttestationMode = strtolower((string) ($idempotency['remote_replay_attestation_mode'] ?? 'allow'));
        if (!in_array($remoteReplayAttestationMode, ['allow', 'warn', 'require'], true)) {
            $remoteReplayAttestationMode = 'allow';
        }
        $remoteReplayAttestationMaxAgeSeconds = max(0, (int) ($idempotency['remote_replay_attestation_max_age_seconds'] ?? 0));
        $remoteReplayValidationMode = strtolower((string) ($idempotency['remote_replay_validation_mode'] ?? 'allow'));
        if (!in_array($remoteReplayValidationMode, ['allow', 'warn', 'require'], true)) {
            $remoteReplayValidationMode = 'allow';
        }
        $remoteReplayValidationReceiptMaxAgeSeconds = max(0, (int) ($idempotency['remote_replay_validation_receipt_max_age_seconds'] ?? 0));
        $remoteReplayValidationReceiptReuseScope = strtolower((string) ($idempotency['remote_replay_validation_receipt_reuse_scope'] ?? 'current_node'));
        if (!in_array($remoteReplayValidationReceiptReuseScope, ['current_node', 'trusted_nodes', 'cluster'], true)) {
            $remoteReplayValidationReceiptReuseScope = 'current_node';
        }
        $remoteReplayValidationReceiptTrustedNodes = is_array($idempotency['remote_replay_validation_receipt_trusted_nodes'] ?? null)
            ? array_values(array_filter(array_map(
                static fn(mixed $value): string => is_string($value) ? trim($value) : '',
                $idempotency['remote_replay_validation_receipt_trusted_nodes'],
            )))
            : [];
        $remoteReplayValidationReceiptPropagationMaxAgeSeconds = max(0, (int) ($idempotency['remote_replay_validation_receipt_propagation_max_age_seconds'] ?? 0));
        $remoteReplayValidationReceiptPropagationHealthLimit = max(1, (int) ($idempotency['remote_replay_validation_receipt_propagation_health_limit'] ?? 250));
        $remoteReplayValidationReceiptPropagationTrustedNodes = is_array($idempotency['remote_replay_validation_receipt_propagation_trusted_nodes'] ?? null)
            ? array_values(array_filter(array_map(
                static fn(mixed $value): string => is_string($value) ? trim($value) : '',
                $idempotency['remote_replay_validation_receipt_propagation_trusted_nodes'],
            )))
            : [];

        return new self(
            timeoutMs: max(1, (int) ($timeouts['soft_timeout_ms'] ?? 30000)),
            maxRows: max(1, (int) ($limits['max_rows'] ?? 100000)),
            maxDepth: max(1, (int) ($limits['max_depth'] ?? 32)),
            retryLimit: max(0, (int) ($resilience['retry_limit'] ?? 1)),
            retryBackoffMs: max(0, (int) ($resilience['retry_backoff_ms'] ?? 10)),
            retryMutationsWhenIdempotent: (bool) ($resilience['retry_mutations_when_idempotent'] ?? false),
            idempotencyPendingTtlSeconds: max(1, (int) ($idempotency['pending_ttl_seconds'] ?? 300)),
            legacyReplayMode: $legacyReplayMode,
            remoteReplayAttestationMode: $remoteReplayAttestationMode,
            remoteReplayAttestationMaxAgeSeconds: $remoteReplayAttestationMaxAgeSeconds,
            remoteReplayValidationMode: $remoteReplayValidationMode,
            remoteReplayValidationReceiptMaxAgeSeconds: $remoteReplayValidationReceiptMaxAgeSeconds,
            remoteReplayValidationReceiptReuseScope: $remoteReplayValidationReceiptReuseScope,
            remoteReplayValidationReceiptTrustedNodes: $remoteReplayValidationReceiptTrustedNodes,
            remoteReplayValidationReceiptPropagationMaxAgeSeconds: $remoteReplayValidationReceiptPropagationMaxAgeSeconds,
            remoteReplayValidationReceiptPropagationHealthLimit: $remoteReplayValidationReceiptPropagationHealthLimit,
            remoteReplayValidationReceiptPropagationTrustedNodes: $remoteReplayValidationReceiptPropagationTrustedNodes,
            circuitFailureThreshold: max(1, (int) ($circuit['failure_threshold'] ?? 3)),
            circuitCooldownMs: max(1, (int) ($circuit['cooldown_ms'] ?? 30000)),
            slowQueryThresholdMs: max(1, (int) ($observability['slow_query_ms'] ?? 250)),
            fallbackEnabled: (bool) ($fallback['enabled'] ?? false),
            fallbackMode: (string) ($fallback['mode'] ?? 'off'),
            fallbackAggregateLimit: max(1, (int) ($fallback['aggregate_limit'] ?? 50)),
            fallbackOpenSegmentsThreshold: max(1, (int) ($fallback['open_segments_threshold'] ?? 1)),
            fallbackHalfOpenSegmentsThreshold: max(1, (int) ($fallback['half_open_segments_threshold'] ?? 1)),
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
            retryMutationsWhenIdempotent: $this->retryMutationsWhenIdempotent,
            idempotencyPendingTtlSeconds: $this->idempotencyPendingTtlSeconds,
            legacyReplayMode: $this->legacyReplayMode,
            remoteReplayAttestationMode: $this->remoteReplayAttestationMode,
            remoteReplayAttestationMaxAgeSeconds: $this->remoteReplayAttestationMaxAgeSeconds,
            remoteReplayValidationMode: $this->remoteReplayValidationMode,
            remoteReplayValidationReceiptMaxAgeSeconds: $this->remoteReplayValidationReceiptMaxAgeSeconds,
            remoteReplayValidationReceiptReuseScope: $this->remoteReplayValidationReceiptReuseScope,
            remoteReplayValidationReceiptTrustedNodes: $this->remoteReplayValidationReceiptTrustedNodes,
            remoteReplayValidationReceiptPropagationMaxAgeSeconds: $this->remoteReplayValidationReceiptPropagationMaxAgeSeconds,
            remoteReplayValidationReceiptPropagationHealthLimit: $this->remoteReplayValidationReceiptPropagationHealthLimit,
            remoteReplayValidationReceiptPropagationTrustedNodes: $this->remoteReplayValidationReceiptPropagationTrustedNodes,
            circuitFailureThreshold: $this->circuitFailureThreshold,
            circuitCooldownMs: $this->circuitCooldownMs,
            slowQueryThresholdMs: $this->slowQueryThresholdMs,
            fallbackEnabled: $this->fallbackEnabled,
            fallbackMode: $this->fallbackMode,
            fallbackAggregateLimit: $this->fallbackAggregateLimit,
            fallbackOpenSegmentsThreshold: $this->fallbackOpenSegmentsThreshold,
            fallbackHalfOpenSegmentsThreshold: $this->fallbackHalfOpenSegmentsThreshold,
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
            retryMutationsWhenIdempotent: $this->retryMutationsWhenIdempotent,
            idempotencyPendingTtlSeconds: $this->idempotencyPendingTtlSeconds,
            legacyReplayMode: $this->legacyReplayMode,
            remoteReplayAttestationMode: $this->remoteReplayAttestationMode,
            remoteReplayAttestationMaxAgeSeconds: $this->remoteReplayAttestationMaxAgeSeconds,
            remoteReplayValidationMode: $this->remoteReplayValidationMode,
            remoteReplayValidationReceiptMaxAgeSeconds: $this->remoteReplayValidationReceiptMaxAgeSeconds,
            remoteReplayValidationReceiptReuseScope: $this->remoteReplayValidationReceiptReuseScope,
            remoteReplayValidationReceiptTrustedNodes: $this->remoteReplayValidationReceiptTrustedNodes,
            remoteReplayValidationReceiptPropagationMaxAgeSeconds: $this->remoteReplayValidationReceiptPropagationMaxAgeSeconds,
            remoteReplayValidationReceiptPropagationHealthLimit: $this->remoteReplayValidationReceiptPropagationHealthLimit,
            remoteReplayValidationReceiptPropagationTrustedNodes: $this->remoteReplayValidationReceiptPropagationTrustedNodes,
            circuitFailureThreshold: $this->circuitFailureThreshold,
            circuitCooldownMs: $this->circuitCooldownMs,
            slowQueryThresholdMs: $this->slowQueryThresholdMs,
            fallbackEnabled: $this->fallbackEnabled,
            fallbackMode: $this->fallbackMode,
            fallbackAggregateLimit: $this->fallbackAggregateLimit,
            fallbackOpenSegmentsThreshold: $this->fallbackOpenSegmentsThreshold,
            fallbackHalfOpenSegmentsThreshold: $this->fallbackHalfOpenSegmentsThreshold,
            audit: $this->audit,
            strict: $this->strict,
            redactBindings: $this->redactBindings,
        );
    }
}