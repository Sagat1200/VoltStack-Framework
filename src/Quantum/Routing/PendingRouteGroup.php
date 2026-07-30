<?php

declare(strict_types=1);

namespace Quantum\Routing;

final class PendingRouteGroup
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private readonly Router $router,
        private array $attributes = [],
    ) {}

    public function prefix(string $prefix): self
    {
        $this->attributes['prefix'] = $prefix;

        return $this;
    }

    public function name(string $name): self
    {
        $this->attributes['name'] = $name;

        return $this;
    }

    public function domain(string $domain): self
    {
        $this->attributes['domain'] = $domain;

        return $this;
    }

    public function middleware(mixed $middleware): self
    {
        $this->attributes['middleware'] = $middleware;

        return $this;
    }

    public function metadata(array $metadata): self
    {
        $this->attributes['metadata'] = $metadata;

        return $this;
    }

    public function meta(array $metadata): self
    {
        return $this->metadata($metadata);
    }

    public function runtimeMeta(string|array $key, mixed $value = null): self
    {
        $metadata = $this->attributes['metadata'] ?? [];

        if (! is_array($metadata)) {
            $metadata = [];
        }

        $runtime = $metadata['runtime'] ?? [];

        if (is_string($runtime) && trim($runtime) !== '') {
            $runtime = ['mode' => trim($runtime)];
        }

        if (! is_array($runtime)) {
            $runtime = [];
        }

        if (is_array($key)) {
            $runtime = array_replace($runtime, $key);
        } else {
            $normalizedKey = trim($key);

            if ($normalizedKey === '') {
                throw new \InvalidArgumentException('Route group runtime metadata key cannot be empty.');
            }

            $runtime[$normalizedKey] = $value;
        }

        $metadata['runtime'] = $runtime;
        $this->attributes['metadata'] = $metadata;

        return $this;
    }

    public function documentContract(string $value): self
    {
        return $this->runtimeMeta('document', $value);
    }

    public function navigationMode(string $value): self
    {
        return $this->runtimeMeta('navigation', $value);
    }

    public function forceReload(): self
    {
        return $this->runtimeMeta([
            'document' => 'reload',
            'navigation' => 'reload',
        ]);
    }

    public function forceSpa(): self
    {
        return $this->runtimeMeta([
            'document' => 'spa',
            'navigation' => 'auto',
        ]);
    }

    public function group(callable $callback): void
    {
        $this->router->group($this->attributes, $callback);
    }
}
