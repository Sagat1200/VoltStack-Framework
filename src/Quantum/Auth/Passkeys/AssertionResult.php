<?php

declare(strict_types=1);

namespace Quantum\Auth\Passkeys;

final readonly class AssertionResult
{
    public function __construct(
        public bool $isValid,
        public ?string $credentialId = null,
        public ?string $userHandle = null,
        public int $signCountIncremented = 0,
        public bool $signatureVerified = false,
        public int $newCounter = 0,
        /** @var array<string, mixed> */
        public array $metadata = [],
    ) {
    }
}
