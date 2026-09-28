<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

final readonly class NonceValidationResult
{
    public function __construct(
        public bool $valid,
        public ?string $reasonCode = null,
        public ?NonceRecord $nonceRecord = null,
    ) {
    }

    public static function ok(NonceRecord $record): self
    {
        return new self(valid: true, reasonCode: null, nonceRecord: $record);
    }

    public static function fail(string $reasonCode): self
    {
        return new self(valid: false, reasonCode: $reasonCode, nonceRecord: null);
    }
}
