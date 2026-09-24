<?php

declare(strict_types=1);

namespace Quantum\Authorization\Metadata;

final readonly class AuthorizationRequirement
{
    public function __construct(
        private string $ability,
        private mixed $subject = null,
        private string $source = 'metadata',
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

    /**
     * @return array{ability:string,subject:mixed,source:string}
     */
    public function toArray(): array
    {
        return [
            'ability' => $this->ability,
            'subject' => $this->subject,
            'source' => $this->source,
        ];
    }
}
