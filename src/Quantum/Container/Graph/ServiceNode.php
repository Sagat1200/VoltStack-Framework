<?php

declare(strict_types=1);

namespace Quantum\Container\Graph;

final readonly class ServiceNode
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
}
