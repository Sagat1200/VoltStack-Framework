<?php

declare(strict_types=1);

namespace Quantum\Routing;

final class Route extends CompiledRoute
{
    private const CONTEXT_HTTP = 'http';
    private const CONTEXT_SPA = 'spa';
    private const CONTEXT_API = 'api';
    private const UUID_PATTERN = '[0-9a-fA-F]{8}\-[0-9a-fA-F]{4}\-[1-5][0-9a-fA-F]{3}\-[89abAB][0-9a-fA-F]{3}\-[0-9a-fA-F]{12}';
    private const ALPHA_PATTERN = '[A-Za-z]+';
    private const ALPHA_NUMERIC_PATTERN = '[A-Za-z0-9]+';
    private const NUMBER_PATTERN = '[0-9]+';
    private const SLUG_PATTERN = '[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*';
    private const ULID_PATTERN = '[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}';

    private ?RouteCollection $collection = null;
    private $middlewareResolver = null;
    private string $namePrefix = '';

    public function __construct(RouteDefinition $definition)
    {
        parent::__construct($definition);
    }

    public function attachCollection(RouteCollection $collection): void
    {
        $this->collection = $collection;
    }

    public function attachMiddlewareResolver(callable $resolver): void
    {
        $this->middlewareResolver = $resolver;
    }

    public function attachNamePrefix(string $prefix): void
    {
        $this->namePrefix = trim($prefix);
    }

    public function name(?string $name = null): string|static|null
    {
        if ($name === null) {
            return $this->routeName();
        }

        $name = $this->qualifyRouteName($name);
        $previousName = $this->routeName();

        if ($this->collection !== null) {
            $this->collection->validateRouteName($this, $name, $previousName);
        }

        $this->replaceDefinition($this->definition()->withName($name));

        if ($this->collection !== null) {
            $this->collection->syncRouteName($this, $previousName);
        }

        return $this;
    }

    public function domain(?string $domain = null): string|static|null
    {
        if ($domain === null) {
            return $this->definition()->domain();
        }

        $previousDomain = $this->definition()->domain();

        if ($this->collection !== null) {
            $this->collection->validateRouteDomain($this, $domain, $previousDomain);
        }

        $this->replaceDefinition($this->definition()->withDomain($domain));

        if ($this->collection !== null) {
            $this->collection->syncRouteDomain($this, $previousDomain);
        }

        return $this;
    }

    public function renameParameter(string $from, string $to): static
    {
        $previousUri = $this->definition()->uri();
        $definition = $this->definition()->renameParameter($from, $to);

        if ($definition === $this->definition()) {
            return $this;
        }

        if ($this->collection !== null) {
            $this->collection->validateRoutePath($this, $definition->uri());
        }

        $this->replaceDefinition($definition);

        if ($this->collection !== null) {
            $this->collection->syncRoutePath($this, $previousUri);
        }

        return $this;
    }

    public function repath(string $uri): static
    {
        $previousUri = $this->definition()->uri();
        $definition = $this->definition()->withPath($uri);

        if ($definition === $this->definition()) {
            return $this;
        }

        if ($this->collection !== null) {
            $this->collection->validateRoutePath($this, $definition->uri());
        }

        $this->replaceDefinition($definition);

        if ($this->collection !== null) {
            $this->collection->syncRoutePath($this, $previousUri);
        }

        return $this;
    }

    /**
     * @param array<int, string> $methods
     */
    public function remethod(array $methods): static
    {
        $previousMethods = $this->definition()->methods();
        $definition = $this->definition()->withMethods($methods);

        if ($definition === $this->definition()) {
            return $this;
        }

        if ($this->collection !== null) {
            $this->collection->validateRouteMethods($this, $definition->methods(), $previousMethods);
        }

        $this->replaceDefinition($definition);

        if ($this->collection !== null) {
            $this->collection->syncRouteMethods($this, $previousMethods);
        }

        return $this;
    }

    public function where(string|array $parameter, ?string $pattern = null): static
    {
        if (is_array($parameter)) {
            $this->replaceDefinition($this->definition()->withConstraints($parameter));

            return $this;
        }

        if ($pattern === null) {
            throw new \InvalidArgumentException(sprintf(
                'Route constraint pattern for [%s] is required.',
                $parameter,
            ));
        }

        $this->replaceDefinition($this->definition()->withConstraint($parameter, $pattern));

        return $this;
    }

    public function whereNumber(string|array $parameter): static
    {
        return $this->applyNamedConstraint($parameter, self::NUMBER_PATTERN);
    }

    public function whereAlpha(string|array $parameter): static
    {
        return $this->applyNamedConstraint($parameter, self::ALPHA_PATTERN);
    }

    public function whereAlphaNumeric(string|array $parameter): static
    {
        return $this->applyNamedConstraint($parameter, self::ALPHA_NUMERIC_PATTERN);
    }

    public function whereUuid(string|array $parameter): static
    {
        return $this->applyNamedConstraint($parameter, self::UUID_PATTERN);
    }

    public function whereUlid(string|array $parameter): static
    {
        return $this->applyNamedConstraint($parameter, self::ULID_PATTERN);
    }

    public function whereSlug(string|array $parameter): static
    {
        return $this->applyNamedConstraint($parameter, self::SLUG_PATTERN);
    }

    /**
     * @param array<int, scalar|\UnitEnum> $allowed
     */
    public function whereIn(string $parameter, array $allowed): static
    {
        if ($allowed === []) {
            throw new \InvalidArgumentException(sprintf(
                'Route constraint allowed values for [%s] cannot be empty.',
                $parameter,
            ));
        }

        $escaped = array_map(function (mixed $value) use ($parameter): string {
            if ($value instanceof \BackedEnum) {
                return preg_quote((string) $value->value, '/');
            }

            if ($value instanceof \UnitEnum) {
                return preg_quote($value->name, '/');
            }

            if (! is_scalar($value)) {
                throw new \InvalidArgumentException(sprintf(
                    'Route constraint value for [%s] must be scalar or enum backed by a scalar value.',
                    $parameter,
                ));
            }

            return preg_quote((string) $value, '/');
        }, array_values($allowed));

        return $this->where($parameter, implode('|', $escaped));
    }

    /**
     * @param class-string<\UnitEnum> $enumClass
     */
    public function whereEnum(string $parameter, string $enumClass): static
    {
        if (! enum_exists($enumClass)) {
            throw new \InvalidArgumentException(sprintf(
                'Route enum constraint [%s] must be a valid enum class.',
                $enumClass,
            ));
        }

        $cases = $enumClass::cases();

        if ($cases === []) {
            throw new \InvalidArgumentException(sprintf(
                'Route enum constraint [%s] must define at least one case.',
                $enumClass,
            ));
        }

        return $this->whereIn($parameter, $cases);
    }

    public function middleware(mixed $middleware): static
    {
        $middleware = $this->resolveMiddlewareInput($middleware);

        if (is_array($middleware)) {
            $this->replaceDefinition($this->definition()->withMiddlewares($middleware));

            return $this;
        }

        $this->replaceDefinition($this->definition()->withMiddleware($middleware));

        return $this;
    }

    public function meta(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            $this->replaceDefinition($this->definition()->withMetadataBag($key));

            return $this;
        }

        $this->replaceDefinition($this->definition()->withMetadata($key, $value));

        return $this;
    }

    public function auth(mixed $value = true): static
    {
        return $this->meta('auth', $value);
    }

    public function guest(mixed $value = true): static
    {
        return $this->meta('guest', $value);
    }

    public function csrf(mixed $value = true): static
    {
        return $this->meta('csrf', $value);
    }

    public function throttle(mixed $value): static
    {
        return $this->meta('throttle', $value);
    }

    public function runtime(mixed $value): static
    {
        return $this->meta('runtime', $value);
    }

    public function runtimeMeta(string|array $key, mixed $value = null): static
    {
        $metadata = $this->definition()->metadata();
        $runtime = $metadata['runtime'] ?? [];

        if (is_string($runtime) && trim($runtime) !== '') {
            $runtime = ['mode' => trim($runtime)];
        }

        if (! is_array($runtime)) {
            $runtime = [];
        }

        if (is_array($key)) {
            $runtime = array_replace($runtime, $key);

            return $this->meta('runtime', $runtime);
        }

        $normalizedKey = trim($key);

        if ($normalizedKey === '') {
            throw new \InvalidArgumentException('Route runtime metadata key cannot be empty.');
        }

        $runtime[$normalizedKey] = $value;

        return $this->meta('runtime', $runtime);
    }

    public function documentContract(string $value): static
    {
        return $this->runtimeMeta('document', $value);
    }

    public function navigationMode(string $value): static
    {
        return $this->runtimeMeta('navigation', $value);
    }

    public function forceReload(): static
    {
        return $this->runtimeMeta([
            'document' => 'reload',
            'navigation' => 'reload',
        ]);
    }

    public function forceSpa(): static
    {
        return $this->runtimeMeta([
            'document' => 'spa',
            'navigation' => 'auto',
        ]);
    }

    public function componentPage(): static
    {
        return $this->screen([
            'kind' => 'component',
            'mode' => 'navigable',
        ]);
    }

    public function embeddableComponent(): static
    {
        return $this->screen([
            'kind' => 'component',
            'mode' => 'embeddable',
        ]);
    }

    public function context(string $context): static
    {
        return $this->meta('context', $this->normalizeContext($context));
    }

    public function http(): static
    {
        return $this->context(self::CONTEXT_HTTP);
    }

    public function spa(): static
    {
        return $this->context(self::CONTEXT_SPA);
    }

    public function api(): static
    {
        return $this->context(self::CONTEXT_API);
    }

    private function resolveMiddlewareInput(mixed $middleware): mixed
    {
        if ($this->middlewareResolver === null) {
            return $middleware;
        }

        if (is_array($middleware)) {
            return array_map($this->middlewareResolver, array_values($middleware));
        }

        return ($this->middlewareResolver)($middleware);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function screen(array $attributes): static
    {
        $screen = $this->definition()->metadata()['screen'] ?? null;

        if (! is_array($screen)) {
            $screen = [];
        }

        $this->replaceDefinition($this->definition()->withMetadata('screen', [
            ...$screen,
            ...$attributes,
        ]));

        return $this;
    }

    private function applyNamedConstraint(string|array $parameter, string $pattern): static
    {
        if (is_array($parameter)) {
            $constraints = [];

            foreach ($parameter as $name) {
                $constraints[(string) $name] = $pattern;
            }

            return $this->where($constraints);
        }

        return $this->where($parameter, $pattern);
    }

    private function normalizeContext(string $context): string
    {
        $normalized = strtolower(trim($context));

        if (! in_array($normalized, [
            self::CONTEXT_HTTP,
            self::CONTEXT_SPA,
            self::CONTEXT_API,
        ], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Route context [%s] is invalid. Supported contexts are [http, spa, api].',
                $context,
            ));
        }

        return $normalized;
    }

    private function qualifyRouteName(string $name): string
    {
        $normalizedName = trim($name);

        if ($this->namePrefix === '' || $normalizedName === '') {
            return $normalizedName;
        }

        if ($normalizedName === $this->namePrefix || str_starts_with($normalizedName, $this->namePrefix . '.')) {
            return $normalizedName;
        }

        return $this->namePrefix . '.' . ltrim($normalizedName, '.');
    }
}
