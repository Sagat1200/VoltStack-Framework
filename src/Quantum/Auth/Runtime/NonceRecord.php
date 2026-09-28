<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

final readonly class NonceRecord
{
    /**
     * @param array<string, mixed> $bindingClaims
     */
    public function __construct(
        public string $value,
        public int $issuedAt,
        public int $expiresAt,
        public array $bindingClaims = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'issued_at' => $this->issuedAt,
            'expires_at' => $this->expiresAt,
            'binding_claims' => $this->bindingClaims,
        ];
    }
}
