<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

/**
 * Represents a secondary proof material presented along with a recovery
 * continuation request.
 *
 * The exact shape of `$metadata` is intentionally driver-specific so new
 * evidence kinds (federated id_token hint, signed admin reference, passkey
 * assertion JSON, MFA code with nonce, ...) can be added without changing
 * the value object. Known kind strings are declared in {@see RecoveryEvidenceKind}.
 *
 * @immutable
 */
final readonly class RecoveryEvidence
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $kind,
        public string $value,
        public array $metadata = [],
    ) {}

    /**
     * @return array{kind:string,value:string,metadata:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'value' => $this->value,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @param array{kind?:string,value?:string,metadata?:array<string,mixed>} $array
     */
    public static function fromArray(array $array): self
    {
        $kind = is_string($array['kind'] ?? null) ? trim($array['kind']) : '';
        $value = is_string($array['value'] ?? null) ? trim($array['value']) : '';
        $metadata = isset($array['metadata']) && is_array($array['metadata']) ? $array['metadata'] : [];

        return new self($kind, $value, $metadata);
    }
}
