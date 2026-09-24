<?php

declare(strict_types=1);

namespace Quantum\Authorization\Metadata;

final readonly class AuthorizationMetadata
{
    /**
     * @param list<array{ability:string,subject:mixed,source:string}> $requirements
     */
    public function __construct(
        private bool $public = false,
        private array $requirements = [],
    ) {}

    public static function empty(): self
    {
        return new self(
            public: false,
            requirements: [],
        );
    }

    public function public(): bool
    {
        return $this->public;
    }

    /**
     * @return list<array{ability:string,subject:mixed,source:string}>
     */
    public function requirements(): array
    {
        return $this->requirements;
    }
}
