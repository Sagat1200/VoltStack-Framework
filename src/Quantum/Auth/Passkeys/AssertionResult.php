<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys;

/**
 * @internal skeleton V1 — simulated assert result, NO crypto verification en 082
 */
final readonly class AssertionResult
{
    public function __construct(
        public bool $isValid,
        public ?string $credentialId = null,
        public ?string $userHandle = null,
        public int $signCountIncremented = 0,
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {
    }
}
