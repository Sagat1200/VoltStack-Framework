<?php

declare(strict_types=1);

namespace Quantum\Authorization\Metadata;

final readonly class AuthorizationRequirement
{
    public function __construct(
        private string $ability,
        private mixed $subject = null,
        private string $source = 'metadata',
        private mixed $condition = null,
    ) {}

    public function ability(): string
    {
        return $this->ability;
    }

    public function subject(): mixed
    {
        return $this->subject;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function condition(): mixed
    {
        return $this->condition;
    }

    /**
     * @return array{ability:string,subject:mixed,source:string,condition:mixed}
     */
    public function toArray(): array
    {
        return [
            'ability' => $this->ability,
            'subject' => $this->subject,
            'source' => $this->source,
            'condition' => $this->condition,
        ];
    }
}
