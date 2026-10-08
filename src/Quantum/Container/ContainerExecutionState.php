<?php

declare(strict_types=1);

namespace Quantum\Container;

/**
 * @internal
 */
final class ContainerExecutionState
{
    /**
     * @param list<Binding> $bindingResolutionStack
     */
    public function __construct(
        private readonly ScopeStack $scopeStack,
        private array $bindingResolutionStack = [],
    ) {
    }

    public function scopeStack(): ScopeStack
    {
        return $this->scopeStack;
    }

    public function pushBindingResolution(Binding $binding): void
    {
        $this->bindingResolutionStack[] = $binding;
    }

    public function popBindingResolution(): void
    {
        array_pop($this->bindingResolutionStack);
    }

    /**
     * @return list<Binding>
     */
    public function bindingResolutionStack(): array
    {
        return $this->bindingResolutionStack;
    }
}
