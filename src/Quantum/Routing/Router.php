<?php

declare(strict_types=1);

namespace Quantum\Routing;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Quantum\HttpKernel\CompiledMiddlewarePipeline;
use Quantum\HttpKernel\MiddlewareAliasRegistry;
use Quantum\HttpKernel\MiddlewareStack;
use Quantum\Http\Response;
use Quantum\Http\Request;
use Quantum\Routing\Dispatching\DispatcherResolver;
use Quantum\Routing\Exceptions\MethodNotAllowedException;
use Quantum\Routing\Exceptions\RouteUrlGenerationException;
use VoltStack\Framework\Application;

final class Router
{
    private RouteCollection $routes;
    private RouteMatcher $matcher;
    private ?CompiledRouteCollection $artifactCollection = null;
    private ?MetadataArtifact $artifactMetadata = null;
    private ?RouteMatchTree $artifactTree = null;
    private ?VersionArtifact $artifactVersion = null;
    private bool $artifactCollectionLoaded = false;
    private bool $artifactMetadataLoaded = false;
    private bool $artifactTreeLoaded = false;
    private bool $artifactVersionLoaded = false;
    private bool $developmentArtifactsInvalidated = false;
    private bool $preferArtifactCollection = false;
    /**
     * @var array<string, CompiledMiddlewarePipeline>
     */
    private array $artifactPipelines = [];
    private bool $artifactPipelinesLoaded = false;
    /**
     * @var array<int, array{prefix: string, name: string, domain: ?string, middleware: array<int, mixed>, metadata: array<string, mixed>}>
     */
    private array $groupStack = [];

    public function __construct(private readonly Application $app)
    {
        $this->routes = new RouteCollection();
        $this->matcher = new RouteMatcher();
    }

    public function get(string $uri, mixed $action): Route
    {
        return $this->addRoute(['GET'], $uri, $action);
    }

    public function post(string $uri, mixed $action): Route
    {
        return $this->addRoute(['POST'], $uri, $action);
    }

    public function put(string $uri, mixed $action): Route
    {
        return $this->addRoute(['PUT'], $uri, $action);
    }

    public function patch(string $uri, mixed $action): Route
    {
        return $this->addRoute(['PATCH'], $uri, $action);
    }

    public function delete(string $uri, mixed $action): Route
    {
        return $this->addRoute(['DELETE'], $uri, $action);
    }

    public function head(string $uri, mixed $action): Route
    {
        return $this->addRoute(['HEAD'], $uri, $action);
    }

    public function options(string $uri, mixed $action): Route
    {
        return $this->addRoute(['OPTIONS'], $uri, $action);
    }

    /**
     * @param array<int, string> $methods
     */
    public function match(array $methods, string $uri, mixed $action): Route
    {
        return $this->addRoute($methods, $uri, $action);
    }

    public function any(string $uri, mixed $action): Route
    {
        return $this->addRoute(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $uri, $action);
    }

    public function attributeRoutes(string|array $controllers): void
    {
        (new AttributeRouteRegistrar($this))->register($controllers);
    }

    public function aliasMiddleware(string $alias, mixed $middleware): void
    {
        $this->middlewareAliases()->alias($alias, $middleware);
    }

    public function prefix(string $prefix): PendingRouteGroup
    {
        return new PendingRouteGroup($this, ['prefix' => $prefix]);
    }

    public function name(string $name): PendingRouteGroup
    {
        return new PendingRouteGroup($this, ['name' => $name]);
    }

    public function domain(string $domain): PendingRouteGroup
    {
        return new PendingRouteGroup($this, ['domain' => $domain]);
    }

    public function group(array|callable $attributes, ?callable $callback = null): void
    {
        if (is_callable($attributes) && $callback === null) {
            $callback = $attributes;
            $attributes = [];
        }

        if (! is_array($attributes) || ! is_callable($callback)) {
            throw new \InvalidArgumentException('Router::group expects attributes and a callback.');
        }

        $this->groupStack[] = $this->mergeGroupAttributes($this->currentGroupAttributes(), $attributes);

        try {
            $this->invokeGroupCallback($callback);
        } finally {
            array_pop($this->groupStack);
        }
    }

    public function resource(string $resource, string $controller): PendingResourceRegistration
    {
        $segments = $this->resourceSegments($resource);

        if ($segments === []) {
            throw new \InvalidArgumentException('Router::resource expects a non-empty resource name.');
        }

        $resourceKey = $this->resourceKey($segments[array_key_last($segments)]);
        $resourceParameters = $this->resourceParameters($segments);
        $parameter = $resourceParameters[$resourceKey];
        $resourceName = implode('.', $segments);
        $collectionPath = $this->resourceCollectionPath($segments, $resourceParameters);
        $routes = [
            'index' => $this->get($collectionPath, $controller . '@index')->name($resourceName . '.index'),
            'create' => $this->get($collectionPath . '/create', $controller . '@create')->name($resourceName . '.create'),
            'store' => $this->post($collectionPath, $controller . '@store')->name($resourceName . '.store'),
            'show' => $this->get($collectionPath . '/{' . $parameter . '}', $controller . '@show')->name($resourceName . '.show'),
            'edit' => $this->get($collectionPath . '/{' . $parameter . '}/edit', $controller . '@edit')->name($resourceName . '.edit'),
            'update' => $this->match(['PUT', 'PATCH'], $collectionPath . '/{' . $parameter . '}', $controller . '@update')->name($resourceName . '.update'),
            'destroy' => $this->delete($collectionPath . '/{' . $parameter . '}', $controller . '@destroy')->name($resourceName . '.destroy'),
        ];

        return new PendingResourceRegistration(
            $this->routes,
            $resourceParameters,
            $resourceKey,
            $segments[array_key_last($segments)],
            $routes,
        );
    }

    public function apiResource(string $resource, string $controller): PendingResourceRegistration
    {
        return $this->resource($resource, $controller)->except(['create', 'edit']);
    }

    /**
     * @param array<int, string> $methods
     */
    public function addRoute(array $methods, string $uri, mixed $action): Route
    {
        $groupAttributes = $this->currentGroupAttributes();
        $route = new Route(RouteDefinition::make(
            $methods,
            $this->mergeGroupPrefix($groupAttributes['prefix'], $uri),
            $action,
        ));
        $route->attachMiddlewareResolver(fn(mixed $middleware): mixed => $this->middlewareAliases()->resolve($middleware));
        $route->attachNamePrefix($groupAttributes['name']);

        if ($groupAttributes['domain'] !== null) {
            $route->domain($groupAttributes['domain']);
        }

        if ($groupAttributes['middleware'] !== []) {
            $route->middleware($groupAttributes['middleware']);
        }

        if ($groupAttributes['metadata'] !== []) {
            $route->meta($groupAttributes['metadata']);
        }

        $registered = $this->routes->add($route);
        $this->artifactCollectionLoaded = false;
        $this->artifactCollection = null;
        $this->artifactMetadataLoaded = false;
        $this->artifactMetadata = null;
        $this->artifactTreeLoaded = false;
        $this->artifactTree = null;
        $this->artifactVersionLoaded = false;
        $this->artifactVersion = null;
        $this->developmentArtifactsInvalidated = false;
        $this->preferArtifactCollection = false;

        return $registered;
    }

    /**
     * @return array<int, Route>
     */
    public function routes(): array
    {
        return $this->routes->all();
    }

    public function collection(): RouteCollection
    {
        return $this->routes;
    }

    public function compiledCollection(): CompiledRouteCollection
    {
        $this->enableArtifactsForProduction();

        if (! $this->preferArtifactCollection) {
            return $this->routes->compiled();
        }

        $this->loadCollectionArtifacts();

        return $this->artifactCollection ?? $this->routes->compiled();
    }

    public function reloadCollectionArtifacts(): void
    {
        $this->preferArtifactCollection = true;
        $this->artifactCollectionLoaded = false;
        $this->artifactCollection = null;
        $this->artifactMetadataLoaded = false;
        $this->artifactMetadata = null;
        $this->artifactTreeLoaded = false;
        $this->artifactTree = null;
        $this->artifactVersionLoaded = false;
        $this->artifactVersion = null;
        $this->developmentArtifactsInvalidated = false;
        $this->loadCollectionArtifacts();
        $this->loadMetadataArtifacts();
        $this->loadTreeArtifacts();
    }

    public function reloadPipelineArtifacts(): void
    {
        $this->artifactPipelinesLoaded = false;
        $this->artifactPipelines = [];
        $this->developmentArtifactsInvalidated = false;
        $this->loadPipelineArtifacts();
    }

    public function canServeCompiledRoutesWithoutLiveRegistration(): bool
    {
        if (! $this->artifactsEnabledForRuntime()) {
            return false;
        }

        if (! $this->shouldUseArtifactsInProduction()) {
            return false;
        }

        if (! is_file($this->app->make(CollectionArtifactStore::class)->path())) {
            return false;
        }

        return $this->artifactsManifestAllows(['collection', 'metadata', 'tree']);
    }

    public function resolvedRoutePipeline(CompiledRoute $route): CompiledMiddlewarePipeline
    {
        $this->enableArtifactsForProduction();
        $this->loadPipelineArtifacts();

        return $this->artifactPipelines[$route->routePipeline()->id()] ?? $route->routePipeline();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function route(string $name, array $parameters = [], bool $absolute = false): string
    {
        $normalizedName = trim($name);
        $route = $this->compiledCollection()->named($normalizedName);

        if ($route === null) {
            throw RouteUrlGenerationException::forUnknownRoute($normalizedName);
        }

        $fragment = $this->extractRouteFragment($parameters, $normalizedName);
        $explicitQuery = $this->extractRouteQuery($parameters, $normalizedName);
        $consumedParameters = [];
        $host = $route->routeDomain() === null
            ? null
            : $this->replaceRouteParameters($route->routeDomain(), $parameters, $normalizedName, $consumedParameters);
        $path = $this->replaceRouteParameters($route->uri(), $parameters, $normalizedName, $consumedParameters);

        foreach (array_keys($consumedParameters) as $parameterName) {
            unset($parameters[$parameterName]);
        }

        $queryParameters = array_replace($parameters, $explicitQuery);
        $url = $absolute
            ? $this->absoluteRouteUrl($path, $host)
            : $this->relativeRouteUrl($path, $host);

        return $this->appendQueryStringAndFragment($url, $queryParameters, $fragment);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function signedRoute(string $name, array $parameters = [], bool $absolute = true): string
    {
        $unsignedUrl = $this->route($name, $parameters, $absolute);
        $fragment = parse_url($unsignedUrl, PHP_URL_FRAGMENT);
        $signature = $this->signUrl($this->canonicalUrlForSignature($unsignedUrl, $absolute));

        return $this->appendQueryStringAndFragment(
            $this->withoutFragment($unsignedUrl),
            ['signature' => $signature],
            is_string($fragment) && $fragment !== '' ? $fragment : null,
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function temporarySignedRoute(
        string $name,
        DateInterval|DateTimeInterface|int $expiration,
        array $parameters = [],
        bool $absolute = true,
    ): string {
        return $this->signedRoute(
            $name,
            array_replace($parameters, [
                'expires' => $this->signatureExpirationTimestamp($expiration),
            ]),
            $absolute,
        );
    }

    public function hasValidSignature(Request $request, bool $absolute = true): bool
    {
        $signature = $request->queryParam('signature');

        if (! is_string($signature) || trim($signature) === '') {
            return false;
        }

        if ($this->hasExpiredSignature($request)) {
            return false;
        }

        return hash_equals(
            $this->signUrl($this->canonicalUrlForRequestSignature($request, $absolute)),
            $signature,
        );
    }

    public function dispatch(Request $request): mixed
    {
        $collection = $this->compiledCollection();

        try {
            $match = $this->matcher->match($request, $collection, $this->resolvedMatchTree($collection));
        } catch (MethodNotAllowedException $exception) {
            if ($request->method() === 'OPTIONS') {
                return new Response('', 204, [
                    'Allow' => $exception->allowHeader(),
                ]);
            }

            throw $exception;
        }

        $request->setRouteParameters($match->parameters());
        $request->setRouteMetadata($match->metadata()->all());

        return $this->resolvedRoutePipeline($match->route())->handle(
            $this->app,
            $request,
            fn(Request $request): mixed => $this->app->make(DispatcherResolver::class)->dispatch($match, $request),
        );
    }

    /**
     * @return array{prefix: string, name: string, domain: ?string, middleware: array<int, mixed>, metadata: array<string, mixed>}
     */
    private function currentGroupAttributes(): array
    {
        return $this->groupStack === []
            ? ['prefix' => '', 'name' => '', 'domain' => null, 'middleware' => [], 'metadata' => []]
            : $this->groupStack[array_key_last($this->groupStack)];
    }

    /**
     * @param array{prefix: string, name: string, domain: ?string, middleware: array<int, mixed>, metadata: array<string, mixed>} $parent
     * @param array<string, mixed> $attributes
     * @return array{prefix: string, name: string, domain: ?string, middleware: array<int, mixed>, metadata: array<string, mixed>}
     */
    private function mergeGroupAttributes(array $parent, array $attributes): array
    {
        $prefix = $parent['prefix'];

        if (isset($attributes['prefix']) && is_string($attributes['prefix'])) {
            $prefix = $this->mergeGroupPrefix($prefix, $attributes['prefix']);
        }

        $name = $parent['name'];

        if (isset($attributes['name']) && is_string($attributes['name'])) {
            $name = $this->mergeGroupNamePrefix($name, $attributes['name']);
        }

        $domain = $parent['domain'];

        if (array_key_exists('domain', $attributes)) {
            $domain = is_string($attributes['domain']) && $attributes['domain'] !== ''
                ? $attributes['domain']
                : null;
        }

        $middlewares = $parent['middleware'];

        if (array_key_exists('middleware', $attributes)) {
            $resolvedMiddlewares = $this->middlewareAliases()->resolveMany(
                is_array($attributes['middleware']) ? array_values($attributes['middleware']) : [$attributes['middleware']]
            );

            $middlewares = MiddlewareStack::deduplicate([
                ...$middlewares,
                ...$resolvedMiddlewares,
            ]);
        }

        $metadata = $parent['metadata'];

        if (array_key_exists('metadata', $attributes) && is_array($attributes['metadata'])) {
            $incoming = $attributes['metadata'];

            if (isset($incoming['runtime']) && is_array($incoming['runtime'])) {
                $parentRuntime = isset($metadata['runtime']) && is_array($metadata['runtime'])
                    ? $metadata['runtime']
                    : [];
                $incoming['runtime'] = array_replace($parentRuntime, $incoming['runtime']);
            }

            $metadata = array_replace($metadata, $incoming);
        }

        return [
            'prefix' => $prefix,
            'name' => $name,
            'domain' => $domain,
            'middleware' => $middlewares,
            'metadata' => $metadata,
        ];
    }

    private function mergeGroupPrefix(string $prefix, string $uri): string
    {
        $normalizedPrefix = trim($prefix, '/');
        $normalizedUri = trim($uri, '/');

        if ($normalizedPrefix === '') {
            return $normalizedUri === '' ? '/' : $normalizedUri;
        }

        if ($normalizedUri === '') {
            return $normalizedPrefix;
        }

        return $normalizedPrefix . '/' . $normalizedUri;
    }

    private function mergeGroupNamePrefix(string $prefix, string $name): string
    {
        $normalizedPrefix = trim($prefix, " \t\n\r\0\x0B.");
        $normalizedName = trim($name, " \t\n\r\0\x0B.");

        if ($normalizedPrefix === '') {
            return $normalizedName;
        }

        if ($normalizedName === '') {
            return $normalizedPrefix;
        }

        return $normalizedPrefix . '.' . $normalizedName;
    }

    private function invokeGroupCallback(callable $callback): void
    {
        $reflection = new \ReflectionFunction(\Closure::fromCallable($callback));

        if ($reflection->getNumberOfParameters() === 0) {
            $callback();

            return;
        }

        $callback($this);
    }

    private function resourceParameterName(string $resource): string
    {
        $segment = $this->resourceKey($resource);

        if (str_ends_with($segment, 'ies') && strlen($segment) > 3) {
            return substr($segment, 0, -3) . 'y';
        }

        if (str_ends_with($segment, 's') && ! str_ends_with($segment, 'ss') && strlen($segment) > 1) {
            return substr($segment, 0, -1);
        }

        return $segment;
    }

    private function resourceKey(string $resource): string
    {
        $segment = basename(str_replace('\\', '/', $resource));
        $segment = str_replace('-', '_', $segment);

        return $segment;
    }

    /**
     * @param array<int, string> $segments
     * @return array<string, string>
     */
    private function resourceParameters(array $segments): array
    {
        $parameters = [];

        foreach ($segments as $segment) {
            $resourceKey = $this->resourceKey($segment);
            $parameters[$resourceKey] = $this->resourceParameterName($segment);
        }

        return $parameters;
    }

    /**
     * @param array<int, string> $segments
     * @param array<string, string> $resourceParameters
     */
    private function resourceCollectionPath(array $segments, array $resourceParameters): string
    {
        $path = [];
        $lastIndex = array_key_last($segments);

        foreach ($segments as $index => $segment) {
            $path[] = trim($segment, '/');

            if ($index === $lastIndex) {
                continue;
            }

            $path[] = '{' . $resourceParameters[$this->resourceKey($segment)] . '}';
        }

        return implode('/', $path);
    }

    /**
     * @return array<int, string>
     */
    private function resourceSegments(string $resource): array
    {
        $normalized = str_replace('\\', '/', $resource);
        $normalized = str_replace('.', '/', $normalized);
        $normalized = trim($normalized, " \t\n\r\0\x0B/.");

        if ($normalized === '') {
            return [];
        }

        $segments = preg_split('#/+#', $normalized) ?: [];

        return array_values(array_filter($segments, static fn(string $segment): bool => trim($segment) !== ''));
    }

    private function middlewareAliases(): MiddlewareAliasRegistry
    {
        return $this->app->make(MiddlewareAliasRegistry::class);
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, true> $consumedParameters
     */
    private function replaceRouteParameters(string $template, array $parameters, string $routeName, array &$consumedParameters): string
    {
        return preg_replace_callback(
            '/\{([^}]+)\}/',
            function (array $matches) use ($parameters, $routeName, &$consumedParameters): string {
                $parameterName = trim((string) ($matches[1] ?? ''));

                if (! array_key_exists($parameterName, $parameters)) {
                    throw RouteUrlGenerationException::forMissingParameter($routeName, $parameterName);
                }

                $consumedParameters[$parameterName] = true;

                return rawurlencode($this->stringifyRouteValue(
                    $parameters[$parameterName],
                    $routeName,
                    $parameterName,
                ));
            },
            $template,
        ) ?? $template;
    }

    private function stringifyRouteValue(mixed $value, string $routeName, string $parameterName): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        throw RouteUrlGenerationException::forInvalidParameter($routeName, $parameterName, $value);
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>
     */
    private function extractRouteQuery(array &$parameters, string $routeName): array
    {
        if (! array_key_exists('_query', $parameters)) {
            return [];
        }

        $query = $parameters['_query'];
        unset($parameters['_query']);

        if ($query === null) {
            return [];
        }

        if (! is_array($query)) {
            throw RouteUrlGenerationException::forInvalidQuery($routeName);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function extractRouteFragment(array &$parameters, string $routeName): ?string
    {
        if (! array_key_exists('_fragment', $parameters)) {
            return null;
        }

        $fragment = $parameters['_fragment'];
        unset($parameters['_fragment']);

        if ($fragment === null || $fragment === '') {
            return null;
        }

        if ($fragment instanceof \BackedEnum) {
            return (string) $fragment->value;
        }

        if ($fragment instanceof \UnitEnum) {
            return $fragment->name;
        }

        if (is_scalar($fragment) || $fragment instanceof \Stringable) {
            return (string) $fragment;
        }

        throw RouteUrlGenerationException::forInvalidFragment($routeName, $fragment);
    }

    /**
     * @param array<string, mixed> $queryParameters
     */
    private function appendQueryStringAndFragment(string $url, array $queryParameters, ?string $fragment): string
    {
        if ($queryParameters !== []) {
            $query = http_build_query($queryParameters, '', '&', PHP_QUERY_RFC3986);

            if ($query !== '') {
                $separator = str_contains($url, '?') ? '&' : '?';
                $url .= $separator . $query;
            }
        }

        if ($fragment !== null) {
            $url .= '#' . rawurlencode($fragment);
        }

        return $url;
    }

    private function withoutFragment(string $url): string
    {
        $fragmentPosition = strpos($url, '#');

        if ($fragmentPosition === false) {
            return $url;
        }

        return substr($url, 0, $fragmentPosition);
    }

    private function signUrl(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->urlSigningSecret());
    }

    private function hasExpiredSignature(Request $request): bool
    {
        $expires = $request->queryParam('expires');

        if ($expires === null) {
            return false;
        }

        $expiration = $this->normalizeExpirationTimestamp($expires);

        if ($expiration === null) {
            return true;
        }

        return $expiration <= time();
    }

    private function signatureExpirationTimestamp(DateInterval|DateTimeInterface|int $expiration): int
    {
        if ($expiration instanceof DateInterval) {
            return (new DateTimeImmutable())->add($expiration)->getTimestamp();
        }

        if ($expiration instanceof DateTimeInterface) {
            return $expiration->getTimestamp();
        }

        return time() + $expiration;
    }

    private function normalizeExpirationTimestamp(mixed $expiration): ?int
    {
        if (is_int($expiration)) {
            return $expiration;
        }

        if (! is_string($expiration)) {
            return null;
        }

        $normalized = trim($expiration);

        if ($normalized === '' || ! ctype_digit($normalized)) {
            return null;
        }

        return (int) $normalized;
    }

    private function urlSigningSecret(): string
    {
        $secret = (string) $this->app->config('app.key', '');

        if ($secret !== '') {
            return $secret;
        }

        return 'voltstack|' . $this->app->basePath();
    }

    private function canonicalUrlForSignature(string $url, bool $absolute): string
    {
        $parts = parse_url($this->withoutFragment($url));

        if (! is_array($parts)) {
            return $this->withoutFragment($url);
        }

        return $this->canonicalizeUrlParts($parts, $absolute);
    }

    private function canonicalUrlForRequestSignature(Request $request, bool $absolute): string
    {
        $parts = [
            'path' => $request->path(),
            'query' => http_build_query($this->queryWithoutSignature($request->query()), '', '&', PHP_QUERY_RFC3986),
        ];

        if ($absolute) {
            $parts['scheme'] = $this->requestScheme($request);
            $parts['host'] = $request->host();
        }

        return $this->canonicalizeUrlParts($parts, $absolute);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function queryWithoutSignature(array $query): array
    {
        unset($query['signature']);

        $this->sortQueryParameters($query);

        return $query;
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function canonicalizeUrlParts(array $parts, bool $absolute): string
    {
        $queryString = '';

        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
            $normalizedQuery = is_array($query) ? $this->queryWithoutSignature($query) : [];
            $queryString = http_build_query($normalizedQuery, '', '&', PHP_QUERY_RFC3986);
        }

        $path = (string) ($parts['path'] ?? '/');
        $path = $path === '' ? '/' : $path;

        $url = $path;

        if ($absolute) {
            $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
            $host = strtolower((string) ($parts['host'] ?? ''));
            $url = $scheme . '://' . $host . $path;
        }

        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function sortQueryParameters(array &$query): void
    {
        ksort($query);

        foreach ($query as &$value) {
            if (is_array($value)) {
                $this->sortQueryParameters($value);
            }
        }
    }

    private function relativeRouteUrl(string $path, ?string $host): string
    {
        $prefixedPath = $this->prefixConfiguredBasePath($path);

        if ($host === null) {
            return $prefixedPath;
        }

        return '//' . $host . $prefixedPath;
    }

    private function absoluteRouteUrl(string $path, ?string $host): string
    {
        $baseUrl = $this->resolvedBaseUrlParts();
        $origin = $host === null ? $baseUrl['origin'] : $this->originWithHost($baseUrl['origin'], $host);

        return $origin . $this->prefixConfiguredBasePath($path);
    }

    private function prefixConfiguredBasePath(string $path): string
    {
        $basePath = $this->configuredBasePath();

        if ($basePath === '') {
            return $path;
        }

        return $path === '/'
            ? $basePath
            : $basePath . $path;
    }

    private function configuredBasePath(): string
    {
        $baseUrl = trim((string) $this->app->config('app.url', ''));

        if ($baseUrl === '') {
            return '';
        }

        $path = parse_url($baseUrl, PHP_URL_PATH);

        if (! is_string($path) || $path === '' || $path === '/') {
            return '';
        }

        return '/' . trim($path, '/');
    }

    /**
     * @return array{origin: string}
     */
    private function resolvedBaseUrlParts(): array
    {
        $configuredBaseUrl = trim((string) $this->app->config('app.url', ''));

        if ($configuredBaseUrl !== '') {
            $origin = $this->normalizeOrigin($configuredBaseUrl);

            if ($origin === null) {
                throw RouteUrlGenerationException::forInvalidBaseUrl($configuredBaseUrl);
            }

            return ['origin' => $origin];
        }

        $currentRequestOrigin = $this->currentRequestOrigin();

        if ($currentRequestOrigin !== null) {
            return ['origin' => $currentRequestOrigin];
        }

        throw RouteUrlGenerationException::forMissingBaseUrl();
    }

    private function normalizeOrigin(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (! is_string($scheme) || $scheme === '' || ! is_string($host) || $host === '') {
            return null;
        }

        $origin = strtolower($scheme) . '://' . strtolower($host);

        if (isset($parts['port']) && is_int($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    private function currentRequestOrigin(): ?string
    {
        try {
            /** @var Request $request */
            $request = $this->app->make(Request::class);
        } catch (\Throwable) {
            return null;
        }

        $host = trim($request->host());

        if ($host === '') {
            return null;
        }

        return $this->requestScheme($request) . '://' . $host;
    }

    private function requestScheme(Request $request): string
    {
        $uriScheme = parse_url($request->uri(), PHP_URL_SCHEME);

        if (is_string($uriScheme) && $uriScheme !== '') {
            return strtolower($uriScheme);
        }

        $forwardedProto = $request->header('X-Forwarded-Proto');

        if (is_string($forwardedProto) && trim($forwardedProto) !== '') {
            return strtolower(trim(explode(',', $forwardedProto, 2)[0]));
        }

        $requestScheme = $request->server('REQUEST_SCHEME');

        if (is_string($requestScheme) && trim($requestScheme) !== '') {
            return strtolower(trim($requestScheme));
        }

        $https = $request->server('HTTPS');

        if (is_string($https) && $https !== '' && strtoupper($https) !== 'OFF' && $https !== '0') {
            return 'https';
        }

        return 'http';
    }

    private function originWithHost(string $origin, string $host): string
    {
        $parts = parse_url($origin);

        if (! is_array($parts) || ! is_string($parts['scheme'] ?? null)) {
            return 'https://' . $host;
        }

        $prefixedOrigin = strtolower($parts['scheme']) . '://' . $host;

        if (isset($parts['port']) && is_int($parts['port'])) {
            $prefixedOrigin .= ':' . $parts['port'];
        }

        return $prefixedOrigin;
    }

    private function loadCollectionArtifacts(): void
    {
        if ($this->artifactCollectionLoaded) {
            return;
        }

        if (! $this->artifactsEnabledForRuntime() || ! $this->artifactsManifestAllows(['collection', 'metadata', 'tree'])) {
            $this->artifactCollectionLoaded = true;

            return;
        }

        $artifact = $this->app->make(CollectionArtifactStore::class)->load();
        $this->artifactCollection = $artifact?->compileCollection();
        $this->applyLoadedMetadataArtifacts();
        $this->artifactCollectionLoaded = true;
    }

    private function loadMetadataArtifacts(): void
    {
        if ($this->artifactMetadataLoaded) {
            return;
        }

        if (! $this->artifactsEnabledForRuntime() || ! $this->artifactsManifestAllows(['metadata'])) {
            $this->artifactMetadataLoaded = true;

            return;
        }

        $this->artifactMetadata = $this->app->make(MetadataArtifactStore::class)->load();
        $this->artifactMetadataLoaded = true;
        $this->applyLoadedMetadataArtifacts();
    }

    private function loadTreeArtifacts(): void
    {
        if ($this->artifactTreeLoaded) {
            return;
        }

        if (! $this->artifactsEnabledForRuntime() || ! $this->artifactsManifestAllows(['tree'])) {
            $this->artifactTreeLoaded = true;

            return;
        }

        $artifact = $this->app->make(TreeArtifactStore::class)->load();
        $this->artifactTree = $artifact?->compileTree();
        $this->artifactTreeLoaded = true;
    }

    private function loadPipelineArtifacts(): void
    {
        if ($this->artifactPipelinesLoaded) {
            return;
        }

        if (! $this->artifactsEnabledForRuntime() || ! $this->artifactsManifestAllows(['pipeline'])) {
            $this->artifactPipelinesLoaded = true;

            return;
        }

        $artifact = $this->app->make(PipelineArtifactStore::class)->load();
        $this->artifactPipelines = $artifact?->compilePipelines() ?? [];
        $this->artifactPipelinesLoaded = true;
    }

    private function resolvedMatchTree(CompiledRouteCollection $collection): ?RouteMatchTree
    {
        if (! $this->preferArtifactCollection) {
            return null;
        }

        $this->loadTreeArtifacts();

        if ($this->artifactTree === null || $this->artifactTree->routeCount() !== $collection->count()) {
            return null;
        }

        return $this->artifactTree;
    }

    private function applyLoadedMetadataArtifacts(): void
    {
        if (! $this->preferArtifactCollection || $this->artifactCollection === null) {
            return;
        }

        if (! $this->artifactMetadataLoaded) {
            $this->loadMetadataArtifacts();
        }

        if ($this->artifactMetadata === null || $this->artifactMetadata->routeCount() !== $this->artifactCollection->count()) {
            return;
        }

        $this->artifactMetadata->applyTo($this->artifactCollection);
    }

    /**
     * @param array<int, string> $artifactNames
     */
    private function artifactsManifestAllows(array $artifactNames): bool
    {
        $manifest = $this->loadVersionArtifact();

        if ($manifest === null) {
            return true;
        }

        foreach ($artifactNames as $artifactName) {
            if (! $this->manifestEntryIsValid($manifest, $artifactName)) {
                return false;
            }
        }

        return true;
    }

    private function loadVersionArtifact(): ?VersionArtifact
    {
        if ($this->artifactVersionLoaded) {
            return $this->artifactVersion;
        }

        $this->artifactVersion = $this->app->make(VersionArtifactStore::class)->load();
        $this->artifactVersionLoaded = true;

        return $this->artifactVersion;
    }

    private function manifestEntryIsValid(VersionArtifact $manifest, string $artifactName): bool
    {
        return match ($artifactName) {
            'collection' => $manifest->validates(
                'collection',
                $this->app->make(CollectionArtifactStore::class)->path(),
                $this->app->make(CollectionArtifactStore::class)->artifactVersion(),
            ),
            'metadata' => $manifest->validates(
                'metadata',
                $this->app->make(MetadataArtifactStore::class)->path(),
                $this->app->make(MetadataArtifactStore::class)->artifactVersion(),
            ),
            'tree' => $manifest->validates(
                'tree',
                $this->app->make(TreeArtifactStore::class)->path(),
                $this->app->make(TreeArtifactStore::class)->artifactVersion(),
            ),
            'pipeline' => $manifest->validates(
                'pipeline',
                $this->app->make(PipelineArtifactStore::class)->path(),
                $this->app->make(PipelineArtifactStore::class)->artifactVersion(),
            ),
            default => false,
        };
    }

    private function artifactsEnabledForRuntime(): bool
    {
        if ((bool) $this->app->config('routing.artifacts.enabled', true) === false) {
            return false;
        }

        if (! $this->shouldInvalidateArtifactsInDevelopment()) {
            return true;
        }

        $this->invalidateDevelopmentArtifacts();

        return false;
    }

    private function enableArtifactsForProduction(): void
    {
        if ($this->preferArtifactCollection) {
            return;
        }

        if (! $this->shouldUseArtifactsInProduction()) {
            return;
        }

        $this->preferArtifactCollection = true;
    }

    private function shouldInvalidateArtifactsInDevelopment(): bool
    {
        if (! $this->app->isDevelopment()) {
            return false;
        }

        return (bool) $this->app->config('routing.artifacts.invalidate_in_development', true);
    }

    private function shouldUseArtifactsInProduction(): bool
    {
        if (! $this->app->isProduction()) {
            return false;
        }

        return (bool) $this->app->config('routing.artifacts.use_in_production', true);
    }

    private function invalidateDevelopmentArtifacts(): void
    {
        if ($this->developmentArtifactsInvalidated) {
            return;
        }

        foreach ($this->routeArtifactPaths() as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->artifactCollection = null;
        $this->artifactMetadata = null;
        $this->artifactTree = null;
        $this->artifactVersion = null;
        $this->artifactPipelines = [];
        $this->developmentArtifactsInvalidated = true;
    }

    /**
     * @return array<int, string>
     */
    private function routeArtifactPaths(): array
    {
        return array_values($this->app->make(RouteArtifactManager::class)->paths());
    }
}
