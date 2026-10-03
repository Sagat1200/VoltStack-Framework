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

    protected ?ScopeStack $scopeStack = null;

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

        $object = $this->resolve($concrete, $parameters);

        if ($binding?->shared) {
            $this->instances[$abstract] = $object;
        }

        if ($binding?->scoped) {
            $scopeFrame->put($abstract, $object);
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
        return $this->scopeStack ??= new ScopeStack();
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

        if (! $requireActiveFrame) {
            return null;
        }

        throw new \Quantum\Container\Exceptions\BindingResolutionException(sprintf(
            'Scoped binding [%s] requires an active [%s] scope.',
            $binding->abstract,
            $binding->scopeKindName() ?? 'scope',
        ));
    }
}
