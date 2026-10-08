<?php

declare(strict_types=1);

namespace Quantum\Container;

use Quantum\Container\Contracts\ContainerInterface;
use ReflectionParameter;

class Container implements ContainerInterface
{
    /**
     * @var array<string, Binding>
     */
    protected array $bindings = [];

    /**
     * @var array<string, mixed>
     */
    protected array $instances = [];

    /**
     * @var array<string, string>
     */
    protected array $aliases = [];

    protected ?AliasResolver $aliasResolver = null;

    protected ?ConcreteResolver $concreteResolver = null;

    protected ?ClassBuilder $classBuilder = null;

    protected ?ParameterResolver $parameterResolver = null;

    /**
     * @var array<string, ContainerExecutionState>
     */
    protected array $executionStates = [];

    /**
     * @var list<string>
     */
    protected array $executionStateStack = [];

    protected ?ScopeFrame $sharedRootScopeFrame = null;

    public function bind(string $abstract, mixed $concrete = null, bool $shared = false): void
    {
        $abstract = $this->normalize($abstract);
        $concrete ??= $abstract;

        $this->bindings[$abstract] = $shared
            ? Binding::singleton($abstract, $concrete)
            : Binding::transient($abstract, $concrete);
    }

    public function singleton(string $abstract, mixed $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    public function scoped(string $abstract, mixed $concrete = null): void
    {
        $abstract = $this->normalize($abstract);
        $concrete ??= $abstract;

        $this->bindings[$abstract] = Binding::scoped($abstract, $concrete);
    }

    public function scopedFor(string $abstract, mixed $concrete = null, ScopeKind|string $scopeKind = ScopeKind::Request): void
    {
        $abstract = $this->normalize($abstract);
        $concrete ??= $abstract;

        if (is_string($scopeKind)) {
            $scopeKind = ScopeKind::fromName($scopeKind);
        }

        $this->bindings[$abstract] = Binding::scoped($abstract, $concrete, $scopeKind);
    }

    public function instance(string $abstract, mixed $instance): void
    {
        $abstract = $this->normalize($abstract);
        $this->instances[$abstract] = $instance;
    }

    public function scopedInstance(string $abstract, mixed $instance): void
    {
        $abstract = $this->normalize($abstract);
        $this->scopeStack()->current()->put($abstract, $instance);
    }

    public function alias(string $abstract, string $alias): void
    {
        $this->aliases[$alias] = $abstract;
    }

    public function has(string $abstract): bool
    {
        $abstract = $this->normalize($abstract);

        return array_key_exists($abstract, $this->instances)
            || $this->scopeStack()->current()->has($abstract)
            || array_key_exists($abstract, $this->bindings)
            || class_exists($abstract);
    }

    public function make(string $abstract, array $parameters = []): mixed
    {
        $abstract = $this->normalize($abstract);

        if (array_key_exists($abstract, $this->instances)) {
            return $this->instances[$abstract];
        }

        $binding = $this->bindings[$abstract] ?? null;
        $scopeFrame = $binding?->scoped ? $this->scopeFrameForScopedBinding($binding) : $this->scopeStack()->current();

        if ($scopeFrame->has($abstract)) {
            return $scopeFrame->get($abstract);
        }

        $concrete = $binding?->concrete ?? $abstract;

        $this->assertScopedDependencyCompatibility($binding);
        if ($binding !== null) {
            $this->currentExecutionState()->pushBindingResolution($binding);
        }

        try {
            $object = $this->resolve($concrete, $parameters);
        } finally {
            if ($binding !== null) {
                $this->currentExecutionState()->popBindingResolution();
            }
        }

        if ($binding?->shared) {
            $this->instances[$abstract] = $object;
        }

        if ($binding?->scoped) {
            $scopeFrame->put($abstract, $object, $binding->scopeKind);
        }

        return $object;
    }

    public function flushScope(): void
    {
        $this->scopeStack()->flushCurrent();
    }

    public function resolved(string $abstract): bool
    {
        $abstract = $this->normalize($abstract);
        $binding = $this->bindings[$abstract] ?? null;
        $scopeFrame = $binding?->scoped ? $this->scopeFrameForScopedBinding($binding, false) : $this->scopeStack()->current();

        return array_key_exists($abstract, $this->instances)
            || ($scopeFrame?->has($abstract) ?? false);
    }

    public function enterScope(string $name = 'scope'): string
    {
        return $this->scopeStack()->enter($name)->id();
    }

    public function activateExecutionState(): string
    {
        $this->initializeDefaultExecutionState();

        $slotId = bin2hex(random_bytes(12));
        $this->executionStates[$slotId] = new ContainerExecutionState(
            new ScopeStack($this->sharedRootScopeFrame),
        );
        $this->executionStateStack[] = $slotId;

        return $slotId;
    }

    public function deactivateExecutionState(?string $slotId): void
    {
        if ($slotId === null || $slotId === 'default') {
            return;
        }

        unset($this->executionStates[$slotId]);
        $this->executionStateStack = array_values(array_filter(
            $this->executionStateStack,
            static fn (string $activeSlotId): bool => $activeSlotId !== $slotId,
        ));

        $this->initializeDefaultExecutionState();
    }

    public function enterWorkerScope(): string
    {
        $this->assertCanEnterUnitScope(ScopeKind::Worker);

        return $this->enterScope(ScopeKind::Worker->value);
    }

    public function enterRequestScope(): string
    {
        $this->assertCanEnterUnitScope(ScopeKind::Request);

        return $this->enterScope(ScopeKind::Request->value);
    }

    public function enterJobScope(): string
    {
        $this->assertCanEnterUnitScope(ScopeKind::Job);

        return $this->enterScope(ScopeKind::Job->value);
    }

    public function enterCommandScope(): string
    {
        $this->assertCanEnterUnitScope(ScopeKind::Command);

        return $this->enterScope(ScopeKind::Command->value);
    }

    public function enterTenantScope(): string
    {
        $this->assertCanEnterTenantScope();

        return $this->enterScope(ScopeKind::Tenant->value);
    }

    public function runInWorkerScope(callable $callback): mixed
    {
        return $this->executeInScope(fn(): string => $this->enterWorkerScope(), $callback);
    }

    public function runInRequestScope(callable $callback): mixed
    {
        return $this->executeInScope(fn(): string => $this->enterRequestScope(), $callback);
    }

    public function runInJobScope(callable $callback): mixed
    {
        return $this->executeInScope(fn(): string => $this->enterJobScope(), $callback);
    }

    public function runInCommandScope(callable $callback): mixed
    {
        return $this->executeInScope(fn(): string => $this->enterCommandScope(), $callback);
    }

    public function runInTenantScope(callable $callback): mixed
    {
        return $this->executeInScope(fn(): string => $this->enterTenantScope(), $callback);
    }

    public function leaveScope(): void
    {
        $this->scopeStack()->leave();
    }

    public function hasActiveScope(): bool
    {
        return $this->scopeStack()->hasActiveScope();
    }

    public function currentScopeId(): string
    {
        return $this->scopeStack()->current()->id();
    }

    public function currentScopeName(): string
    {
        return $this->scopeStack()->currentName();
    }

    public function currentScopeKind(): string
    {
        return $this->scopeStack()->currentKind()->value;
    }

    public function currentScopeParentId(): ?string
    {
        return $this->scopeStack()->current()->parentId();
    }

    public function currentScopeDepth(): int
    {
        return $this->scopeStack()->current()->depth();
    }

    /**
     * @return list<array{id: string, name: string, kind: string, parent_id: ?string, depth: int}>
     */
    public function currentScopeStack(): array
    {
        return $this->scopeStack()->describe();
    }

    /**
     * @return array<string, Binding>
     */
    public function registeredBindings(): array
    {
        return $this->bindings;
    }

    /**
     * @return array<string, string>
     */
    public function registeredAliases(): array
    {
        return $this->aliases;
    }

    protected function resolve(mixed $concrete, array $parameters = []): mixed
    {
        return $this->concreteResolver()->resolve(
            $concrete,
            $parameters,
            $this,
            fn(string $className, array $runtimeParameters): object => $this->build($className, $runtimeParameters),
        );
    }

    protected function build(string $concrete, array $parameters = []): object
    {
        return $this->classBuilder()->build(
            $concrete,
            $parameters,
            fn(ReflectionParameter $parameter, array $runtimeParameters): mixed => $this->resolveParameter($parameter, $runtimeParameters),
        );
    }

    protected function resolveParameter(ReflectionParameter $parameter, array $parameters): mixed
    {
        return $this->parameterResolver()->resolve(
            $parameter,
            $parameters,
            fn(string $abstract): mixed => $this->make($abstract),
        );
    }

    protected function normalize(string $abstract): string
    {
        return $this->aliasResolver()->normalize($abstract, $this->aliases);
    }

    protected function aliasResolver(): AliasResolver
    {
        return $this->aliasResolver ??= new AliasResolver();
    }

    protected function concreteResolver(): ConcreteResolver
    {
        return $this->concreteResolver ??= new ConcreteResolver();
    }

    protected function classBuilder(): ClassBuilder
    {
        return $this->classBuilder ??= new ClassBuilder();
    }

    protected function parameterResolver(): ParameterResolver
    {
        return $this->parameterResolver ??= new ParameterResolver();
    }

    protected function scopeStack(): ScopeStack
    {
        return $this->currentExecutionState()->scopeStack();
    }

    protected function currentExecutionState(): ContainerExecutionState
    {
        $this->initializeDefaultExecutionState();

        return $this->executionStates[$this->executionStateStack[array_key_last($this->executionStateStack)]];
    }

    protected function initializeDefaultExecutionState(): void
    {
        if (isset($this->executionStates['default'])) {
            if ($this->executionStateStack === []) {
                $this->executionStateStack[] = 'default';
            }

            return;
        }

        $defaultState = new ContainerExecutionState(new ScopeStack());
        $this->executionStates['default'] = $defaultState;
        $this->executionStateStack[] = 'default';
        $this->sharedRootScopeFrame = $defaultState->scopeStack()->root();
    }

    protected function scopeFrameForScopedBinding(Binding $binding, bool $requireActiveFrame = true): ?ScopeFrame
    {
        if ($binding->scopeKind === null) {
            return $this->scopeStack()->current();
        }

        $frame = $this->scopeStack()->findClosestByKind($binding->scopeKind);

        if ($frame !== null) {
            return $frame;
        }

        if ($binding->scopeKind === ScopeKind::Worker) {
            return $this->scopeStack()->root();
        }

        if (! $requireActiveFrame) {
            return null;
        }

        throw new \Quantum\Container\Exceptions\BindingResolutionException(sprintf(
            'Scoped binding [%s] requires an active [%s] scope.',
            $binding->abstract,
            $binding->scopeKindName() ?? 'scope',
        ));
    }

    protected function assertScopedDependencyCompatibility(?Binding $binding): void
    {
        if ($binding === null || ! $binding->scoped) {
            return;
        }

        $parentBinding = $this->nearestRetainingBinding();

        if ($parentBinding === null || ! $parentBinding->storesResolvedInstance()) {
            return;
        }

        if ($parentBinding->shared) {
            throw new \Quantum\Container\Exceptions\BindingResolutionException(sprintf(
                'Singleton binding [%s] cannot retain scoped dependency [%s]%s.',
                $parentBinding->abstract,
                $binding->abstract,
                $binding->scopeKind !== null
                    ? sprintf(' with scope [%s]', $binding->scopeKindName() ?? 'scope')
                    : '',
            ));
        }

        if (! $parentBinding->scoped) {
            return;
        }

        $parentScopeKind = $this->effectiveScopeKind($parentBinding);
        $dependencyScopeKind = $this->effectiveScopeKind($binding);
        $parentOwnerFrame = $this->effectiveOwnerFrame($parentBinding);
        $dependencyOwnerFrame = $this->effectiveOwnerFrame($binding);

        if ($parentScopeKind === null || $dependencyScopeKind === null || $parentOwnerFrame === null || $dependencyOwnerFrame === null) {
            return;
        }

        if ($parentScopeKind->canRetain($dependencyScopeKind)) {
            if ($this->scopeStack()->isAncestorOrSame($dependencyOwnerFrame, $parentOwnerFrame)) {
                return;
            }
        }

        throw new \Quantum\Container\Exceptions\BindingResolutionException(sprintf(
            'Scoped binding [%s] with scope [%s] cannot retain dependency [%s] with scope [%s].',
            $parentBinding->abstract,
            $parentScopeKind->value,
            $binding->abstract,
            $dependencyScopeKind->value,
        ));
    }

    protected function nearestRetainingBinding(): ?Binding
    {
        $bindingResolutionStack = $this->currentExecutionState()->bindingResolutionStack();

        for ($index = count($bindingResolutionStack) - 1; $index >= 0; $index--) {
            $binding = $bindingResolutionStack[$index];

            if ($binding->storesResolvedInstance()) {
                return $binding;
            }
        }

        return null;
    }

    protected function effectiveScopeKind(Binding $binding): ?ScopeKind
    {
        if (! $binding->scoped) {
            return null;
        }

        if ($binding->scopeKind !== null) {
            return $binding->scopeKind;
        }

        return $this->scopeFrameForScopedBinding($binding, false)?->kind();
    }

    protected function effectiveOwnerFrame(Binding $binding): ?ScopeFrame
    {
        if (! $binding->scoped) {
            return null;
        }

        return $this->scopeFrameForScopedBinding($binding, false);
    }

    protected function assertCanEnterUnitScope(ScopeKind $scopeKind): void
    {
        $currentKind = $this->scopeStack()->currentKind();

        if ($scopeKind === ScopeKind::Worker) {
            if ($currentKind === ScopeKind::Root) {
                return;
            }

            throw new \Quantum\Container\Exceptions\BindingResolutionException(sprintf(
                'Cannot enter [%s] scope from [%s] scope.',
                $scopeKind->value,
                $currentKind->value,
            ));
        }

        if (in_array($currentKind, [ScopeKind::Root, ScopeKind::Worker], true)) {
            return;
        }

        throw new \Quantum\Container\Exceptions\BindingResolutionException(sprintf(
            'Cannot enter [%s] scope from [%s] scope.',
            $scopeKind->value,
            $currentKind->value,
        ));
    }

    protected function assertCanEnterTenantScope(): void
    {
        $currentKind = $this->scopeStack()->currentKind();

        if (in_array($currentKind, [ScopeKind::Request, ScopeKind::Job, ScopeKind::Command], true)) {
            return;
        }

        throw new \Quantum\Container\Exceptions\BindingResolutionException(sprintf(
            'Cannot enter [%s] scope from [%s] scope.',
            ScopeKind::Tenant->value,
            $currentKind->value,
        ));
    }

    protected function executeInScope(callable $enterScope, callable $callback): mixed
    {
        $enterScope();

        try {
            return $callback($this);
        } finally {
            $this->leaveScope();
        }
    }
}
