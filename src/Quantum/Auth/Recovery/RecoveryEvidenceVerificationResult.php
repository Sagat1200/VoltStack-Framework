<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

/**
 * Outcome of evaluating a single evidence presented alongside a recovery
 * continuation. Verifiers return this VO so callers can project per-evidence
 * failure reasons without needing to know verifier internals.
 *
 * `$passed` controls whether the evidence allows continuation. When a
 * verifier returns `skip=true` it means "this kind was not mine" and the
 * orchestrator should try another verifier or fall back to policy.
 *
 * @immutable
 */
final readonly class RecoveryEvidenceVerificationResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $kind,
        public bool $passed,
        public bool $skip = false,
        public string $reasonCode = '',
        public array $metadata = [],
    ) {}

    public static function passed(string $kind, string $reasonCode = '', array $metadata = []): self
    {
        return new self($kind, true, false, $reasonCode, $metadata);
    }

    public static function failed(string $kind, string $reasonCode, array $metadata = []): self
    {
        return new self($kind, false, false, $reasonCode, $metadata);
    }

    public static function skip(string $kind, string $reasonCode = 'verifier.kind_mismatch', array $metadata = []): self
    {
        return new self($kind, false, true, $reasonCode, $metadata);
    }

    /**
     * @return array{kind:string,passed:bool,skip:bool,reason_code:string,metadata:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'passed' => $this->passed,
            'skip' => $this->skip,
            'reason_code' => $this->reasonCode,
            'metadata' => $this->metadata,
        ];
    }
}
