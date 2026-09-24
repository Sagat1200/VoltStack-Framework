<?php

declare(strict_types=1);

namespace Quantum\Authorization\Metadata;

final readonly class AuthorizationMetadata
{
    /**
     * @param list<AuthorizationRequirement> $requirements
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
     * @return list<AuthorizationRequirement>
     */
    public function requirements(): array
    {
        return $this->requirements;
    }

    public function payload(): AuthorizationMetadataPayload
    {
        return new AuthorizationMetadataPayload(
            public: $this->public,
            requirements: $this->requirements,
        );
    }

    /**
     * @return list<array{ability:string,subject:mixed,source:string}>
     */
    public function requirementsAsArray(): array
    {
        return array_map(
            static fn (AuthorizationRequirement $requirement): array => $requirement->toArray(),
            $this->requirements,
        );
    }
}
