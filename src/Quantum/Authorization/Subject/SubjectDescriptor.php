<?php

declare(strict_types=1);

namespace Quantum\Authorization\Subject;

final readonly class SubjectDescriptor
{
    public function __construct(
        private SubjectType $type,
        private mixed $value = null,
        private ?string $className = null,
    ) {}

    public function type(): SubjectType
    {
        return $this->type;
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function className(): ?string
    {
        return $this->className;
    }

    public function present(): bool
    {
        return $this->type !== SubjectType::None;
    }
}
