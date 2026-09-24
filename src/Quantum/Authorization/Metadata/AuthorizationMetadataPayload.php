<?php

declare(strict_types=1);

namespace Quantum\Authorization\Metadata;

final readonly class AuthorizationMetadataPayload
{
    /**
     * @param list<AuthorizationRequirement> $requirements
     */
    public function __construct(
        private bool $public = false,
        private array $requirements = [],
        private ?string $fingerprint = null,
    ) {}

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

    public function fingerprint(): string
    {
        return $this->fingerprint ?? sha1(json_encode($this->canonicalData(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<AuthorizationRequirement>
     */
    public function matchAbility(string $ability): array
    {
        return array_values(array_filter(
            $this->requirements,
            static fn (AuthorizationRequirement $requirement): bool => $requirement->ability() === $ability,
        ));
    }

    /**
     * @return array{public:bool,requirements:list<array{ability:string,subject:mixed,source:string}>,fingerprint:string}
     */
    public function toArray(): array
    {
        return [
            ...$this->canonicalData(),
            'fingerprint' => $this->fingerprint(),
        ];
    }

    /**
     * @return array{public:bool,requirements:list<array{ability:string,subject:mixed,source:string}>}
     */
    private function canonicalData(): array
    {
        return [
            'public' => $this->public,
            'requirements' => array_map(
                static fn (AuthorizationRequirement $requirement): array => $requirement->toArray(),
                $this->requirements,
            ),
        ];
    }
}
