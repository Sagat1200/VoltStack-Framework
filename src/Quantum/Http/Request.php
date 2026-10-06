<?php

declare(strict_types=1);

namespace Quantum\Http;

final class Request
{
    private const METHOD_OVERRIDE_WHITELIST = ['PUT', 'PATCH', 'DELETE'];
    private const ROUTE_CONTEXT_HTTP = 'http';
    private const ROUTE_CONTEXT_SPA = 'spa';
    private const ROUTE_CONTEXT_API = 'api';
    private const INTERNAL_ROUTE_ENDPOINTS = [
        '/_volt/runtime.js' => 'volt.runtime.asset',
        '/_volt/routes-manifest.json' => 'volt.routes.manifest',
        '/_volt/action' => 'volt.protocol.action',
    ];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $request
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public function __construct(
        private array $query = [],
        private array $request = [],
        private array $attributes = [],
        private array $cookies = [],
        private array $files = [],
        private array $server = [],
        private ?string $content = null,
    ) {}

    public static function capture(): self
    {
        $content = file_get_contents('php://input');

        return new self(
            $_GET,
            self::normalizeInput($_POST, $content ?: null, $_SERVER),
            [],
            $_COOKIE,
            $_FILES,
            $_SERVER,
            $content === false ? null : $content,
        );
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $request
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $cookies
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public static function create(
        string $uri,
        string $method = 'GET',
        array $query = [],
        array $request = [],
        array $attributes = [],
        array $cookies = [],
        array $files = [],
        array $server = [],
        ?string $content = null,
    ): self {
        $server = array_change_key_case($server, CASE_UPPER);
        $query = array_replace(self::queryFromUri($uri), $query);
        $server['REQUEST_METHOD'] = strtoupper($method);
        $server['REQUEST_URI'] = $uri;

        return new self($query, $request, $attributes, $cookies, $files, $server, $content);
    }

    public function method(): string
    {
        $method = $this->originalMethod();

        if (! $this->canOverrideMethod()) {
            return $method;
        }

        $overriddenMethod = $this->methodOverride();

        if ($overriddenMethod === null) {
            return $method;
        }

        return $overriddenMethod;
    }

    public function isSafeMethod(): bool
    {
        return in_array($this->method(), ['GET', 'HEAD', 'OPTIONS'], true);
    }

    public function isStateChangingMethod(): bool
    {
        return ! $this->isSafeMethod();
    }

    public function originalMethod(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function uri(): string
    {
        return (string) ($this->server['REQUEST_URI'] ?? '/');
    }

    public function path(): string
    {
        $path = parse_url($this->uri(), PHP_URL_PATH) ?: '/';

        return $path === '' ? '/' : $path;
    }

    public function host(): string
    {
        $uriHost = parse_url($this->uri(), PHP_URL_HOST);

        if (is_string($uriHost) && $uriHost !== '') {
            return strtolower($uriHost);
        }

        $host = (string) ($this->server['HTTP_HOST'] ?? $this->server['SERVER_NAME'] ?? '');

        if ($host === '') {
            return '';
        }

        $host = explode(':', $host, 2)[0];

        return strtolower($host);
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    /**
     * @return array<string, mixed>
     */
    public function request(): array
    {
        return $this->request;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_replace($this->query, $this->request, $this->attributes);
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->all();
        }

        return $this->all()[$key] ?? $default;
    }

    public function queryParam(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->request[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function setRouteParameters(array $parameters): void
    {
        $this->attributes['_route_params'] = $parameters;

        foreach ($parameters as $key => $value) {
            $this->attributes[$key] = $value;
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function setRouteMetadata(array $metadata): void
    {
        $this->attributes['_route_metadata'] = $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function routeParameters(): array
    {
        $parameters = $this->attributes['_route_params'] ?? [];

        return is_array($parameters) ? $parameters : [];
    }

    public function routeParameter(string $key, mixed $default = null): mixed
    {
        return $this->routeParameters()[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function routeMetadata(): array
    {
        $metadata = $this->attributes['_route_metadata'] ?? [];

        return is_array($metadata) ? $metadata : [];
    }

    public function routeMeta(string $key, mixed $default = null): mixed
    {
        return $this->routeMetadata()[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function routeRuntimeMetadata(): array
    {
        $runtime = $this->routeMeta('runtime', []);

        if (is_array($runtime)) {
            return $runtime;
        }

        if (is_string($runtime) && trim($runtime) !== '') {
            return ['mode' => trim($runtime)];
        }

        return [];
    }

    public function routeRuntimeMeta(string $key, mixed $default = null): mixed
    {
        $runtime = $this->routeRuntimeMetadata();

        if (array_key_exists($key, $runtime)) {
            return $runtime[$key];
        }

        return $this->routeMeta($key, $default);
    }

    public function header(string $key, mixed $default = null): mixed
    {
        $upperKey = strtoupper(str_replace('-', '_', $key));
        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $key));

        if ($upperKey === 'CONTENT_TYPE' || $upperKey === 'CONTENT_LENGTH') {
            return $this->server[$upperKey] ?? $default;
        }

        return $this->server[$normalized] ?? $default;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[strtoupper($key)] ?? $default;
    }

    public function content(): ?string
    {
        return $this->content;
    }

    public function isJson(): bool
    {
        $contentType = strtoupper((string) $this->header('Content-Type', ''));

        return str_contains($contentType, 'APPLICATION/JSON');
    }

    public function isVoltRequest(): bool
    {
        return strtoupper((string) $this->header('X-Requested-With', '')) === 'VOLTSTACK';
    }

    public function isVoltNavigation(): bool
    {
        return strtoupper((string) $this->header('X-Volt-Navigate', '')) === 'TRUE';
    }

    public function routeEndpoint(): ?string
    {
        $endpoint = $this->routeMeta('endpoint');

        if (is_string($endpoint) && trim($endpoint) !== '') {
            return trim($endpoint);
        }

        return self::INTERNAL_ROUTE_ENDPOINTS[$this->path()] ?? null;
    }

    public function routeTransport(): string
    {
        $transport = $this->routeMeta('transport');

        if (is_string($transport) && trim($transport) !== '') {
            return strtolower(trim($transport));
        }

        return $this->routeEndpoint() === null ? 'http' : 'internal';
    }

    public function isInternalEndpoint(): bool
    {
        return $this->routeTransport() === 'internal';
    }

    public function routeContext(): string
    {
        $context = $this->normalizeRouteContext($this->routeMeta('context'));

        if ($context !== null) {
            return $context;
        }

        return $this->routeEndpoint() === null
            ? self::ROUTE_CONTEXT_HTTP
            : self::ROUTE_CONTEXT_SPA;
    }

    public function isHttpRouteContext(): bool
    {
        return $this->routeContext() === self::ROUTE_CONTEXT_HTTP;
    }

    public function isSpaRouteContext(): bool
    {
        return $this->routeContext() === self::ROUTE_CONTEXT_SPA;
    }

    public function isApiRouteContext(): bool
    {
        return $this->routeContext() === self::ROUTE_CONTEXT_API;
    }

    public function isConventionalHttpRequest(): bool
    {
        return ! $this->isInternalEndpoint();
    }

    public function isVoltActionRequest(): bool
    {
        return $this->routeEndpoint() === 'volt.protocol.action'
            || ($this->isVoltRequest() && ! $this->isVoltNavigation() && $this->isJson());
    }

    public function expectsJson(): bool
    {
        $accept = strtoupper((string) $this->header('Accept', ''));
        $requestedWith = strtoupper((string) $this->header('X-Requested-With', ''));

        return $this->isJson()
            || str_contains($accept, 'APPLICATION/JSON')
            || $requestedWith === 'XMLHTTPREQUEST';
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    private static function normalizeInput(array $input, ?string $content, array $server): array
    {
        if ($input !== []) {
            return $input;
        }

        $contentType = strtoupper((string) ($server['CONTENT_TYPE'] ?? ''));

        if ($content === null || ! str_contains($contentType, 'APPLICATION/JSON')) {
            return $input;
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : $input;
    }

    /**
     * @return array<string, mixed>
     */
    private static function queryFromUri(string $uri): array
    {
        $query = parse_url($uri, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return [];
        }

        parse_str($query, $parsed);

        return is_array($parsed) ? $parsed : [];
    }

    private function canOverrideMethod(): bool
    {
        return $this->originalMethod() === 'POST' && $this->isConventionalHttpRequest();
    }

    private function methodOverride(): ?string
    {
        $headerOverride = $this->normalizeMethodOverride($this->header('X-HTTP-Method-Override'));

        if ($headerOverride !== null) {
            return $headerOverride;
        }

        return $this->normalizeMethodOverride($this->request['_method'] ?? null);
    }

    private function normalizeMethodOverride(mixed $method): ?string
    {
        if (! is_string($method)) {
            return null;
        }

        $normalizedMethod = strtoupper(trim($method));

        if ($normalizedMethod === '' || ! in_array($normalizedMethod, self::METHOD_OVERRIDE_WHITELIST, true)) {
            return null;
        }

        return $normalizedMethod;
    }

    private function normalizeRouteContext(mixed $context): ?string
    {
        if (! is_string($context)) {
            return null;
        }

        $normalized = strtolower(trim($context));

        if (! in_array($normalized, [
            self::ROUTE_CONTEXT_HTTP,
            self::ROUTE_CONTEXT_SPA,
            self::ROUTE_CONTEXT_API,
        ], true)) {
            return null;
        }

        return $normalized;
    }
}