<?php

declare(strict_types=1);

namespace Quantum\Config\Validation;

final readonly class ConfigValidationResult
{
    /**
     * @param array<string, mixed> $data
     * @param list<ConfigViolation> $violations
     */
    public function __construct(
        private array $data,
        private array $violations = [],
    ) {
    }

    public function isValid(): bool
    {
        return $this->violations === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * @return list<ConfigViolation>
     */
    public function violations(): array
    {
        return $this->violations;
    }
}
