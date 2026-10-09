<?php

declare(strict_types=1);

namespace Quantum\Container\Graph;

use JsonSerializable;

final readonly class ServiceNode implements JsonSerializable
{
    /**
     * @param list<DependencyReference> $dependencies
     * @param list<ParameterRequirement> $parameters
     */
    public function __construct(
        public string $abstract,
        public string $lifetime,
        public ?string $scopeKind,
        public string $concreteKind,
        public ?string $concreteName,
        public bool $analyzable,
        public array $dependencies,
        public array $parameters,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'abstract' => $this->abstract,
            'lifetime' => $this->lifetime,
            'scope_kind' => $this->scopeKind,
            'concrete_kind' => $this->concreteKind,
            'concrete_name' => $this->concreteName,
            'analyzable' => $this->analyzable,
            'dependencies' => array_map(
                static fn(DependencyReference $dependency): array => $dependency->toArray(),
                $this->dependencies,
            ),
            'parameters' => array_map(
                static fn(ParameterRequirement $parameter): array => $parameter->toArray(),
                $this->parameters,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
