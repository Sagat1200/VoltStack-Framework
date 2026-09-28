<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

final readonly class StepUpRequirement
{
    public function __construct(
        public bool $isRequired,
        public ?AssuranceProfile $requiredProfile = null,
        public ?string $reasonCode = null,
    ) {
    }

    public static function none(): self
    {
        return new self(isRequired: false);
    }

    public static function required(AssuranceProfile $profile, string $reasonCode): self
    {
        return new self(
            isRequired: true,
            requiredProfile: $profile,
            reasonCode: $reasonCode,
        );
    }
}
