<?php

declare(strict_types=1);

namespace VoltStack\Framework;

use Quantum\Config\ConfigRepository;
use Quantum\Auth\Authenticators\PasswordAuthenticator;
use Quantum\Auth\Authenticators\SessionAuthenticator;
use Quantum\Auth\AuthManager;
use Quantum\Auth\Context\AuthenticationContextAccessor;
use Quantum\Auth\Contracts\AuthenticationSessionRepositoryInterface;
use Quantum\Auth\Contracts\AuthenticatorInterface;
use Quantum\Auth\Contracts\AuthenticatorResolverInterface;
use Quantum\Auth\Contracts\AuthenticationManagerInterface;
use Quantum\Auth\Contracts\AuthenticationOrchestratorInterface;
use Quantum\Auth\Contracts\IdentityProviderInterface;
use Quantum\Auth\Identity\LocalIdentityProvider;
use Quantum\Auth\Runtime\AuthenticationOrchestrator;
use Quantum\Auth\Runtime\DefaultAuthenticatorResolver;
use Quantum\Auth\Sessions\InMemoryAuthenticationSessionRepository;
use Quantum\Cache\CacheManager;
use Quantum\Cache\Repository as CacheRepository;
use Quantum\Compilation\ArtifactStore;
use Quantum\Compilation\BuildManifest;
use Quantum\Compilation\CompiledControllerFactory;
use Quantum\Compilation\Compiler;
use Quantum\Compilation\Contracts\ArtifactStoreInterface;
use Quantum\Compilation\Contracts\BuildManifestInterface;
use Quantum\Compilation\Contracts\CompiledControllerFactoryInterface;
use Quantum\Compilation\Contracts\CompilerInterface;
use Quantum\Controllers\Interceptors\ControllerInterceptorRegistry;
use Quantum\Controllers\Interceptors\Conditions\EnvironmentInterceptorCondition;
use Quantum\Controllers\Interceptors\Conditions\HttpMethodInterceptorCondition;
use Quantum\Controllers\Interceptors\Conditions\InterceptorConditionRegistry;
use Quantum\Controllers\Interceptors\Conditions\RouteNameInterceptorCondition;
use Quantum\Controllers\Interceptors\Contracts\ControllerInterceptorRegistryInterface;
use Quantum\Controllers\Metadata\ControllerMetadataResolver;
use Quantum\Controllers\Metadata\ControllerMetadataResolverInterface;
use Quantum\Controllers\Observability\Contracts\ControllerEventDispatcherInterface;
use Quantum\Controllers\Observability\Contracts\ControllerObservabilityManagerInterface;
use Quantum\Controllers\Observability\Engine\ControllerObservabilityManager;
use Quantum\Controllers\Observability\Engine\InMemoryControllerEventDispatcher;
use Quantum\Controllers\Observability\Engine\JsonLineControllerEventDispatcher;
use Quantum\Controllers\Observability\Engine\NullControllerEventDispatcher;
use Quantum\Controllers\Runtime\ControllerRuntimeResolver;
use Quantum\Controllers\Runtime\ControllerRuntimeResolverInterface;
use Quantum\Container\Container;
use Quantum\Container\Contracts\ContainerInterface;
use Quantum\Exceptions\Contracts\ExceptionHandlerInterface as QuantumExceptionHandlerInterface;
use Quantum\Exceptions\ExceptionHandler as QuantumExceptionHandler;
use Quantum\Http\HtmlDocumentBootstrapper;
use Quantum\Http\Request;
use Quantum\Http\ResponseFactory;
use Quantum\HttpKernel\MiddlewareAliasRegistry;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Middlewares\ValidateSignatureMiddleware;
use Quantum\Routing\Dispatching\ResponseNormalizer;
use Quantum\Routing\CollectionArtifactStore;
use Quantum\Routing\MetadataArtifactStore;
use Quantum\Routing\FrontendRouteManifestStore;
use Quantum\Routing\SpaNavigationPayloadFactory;
use Quantum\Metadata\Contracts\MetadataEngineInterface;
use Quantum\Metadata\MetadataEngine;
use Quantum\Metadata\MetadataMerger;
use Quantum\Metadata\MetadataMergeStrategy;
use Quantum\Metadata\MetadataNormalizer as MetadataValueNormalizer;
use Quantum\Metadata\MetadataProviderPipeline;
use Quantum\Metadata\MetadataProviderRegistry;
use Quantum\Metadata\MetadataValueType;
use Quantum\Metadata\Providers\AttributeMetadataProvider;
use Quantum\Metadata\Providers\ConfigMetadataProvider;
use Quantum\Metadata\Providers\ConventionMetadataProvider;
use Quantum\Metadata\Providers\ReflectionMetadataProvider;
use Quantum\Metadata\Providers\RouteMetadataProvider;
use Quantum\Metadata\Schema\MetadataSchema;
use Quantum\Metadata\Schema\MetadataSchemaRegistry;
use Quantum\Middlewares\CsrfMiddleware;
use Quantum\Transport\Adapters\HttpTransportAdapter;
use Quantum\Transport\Bridges\Http\HttpKernelTransportKernel;
use Quantum\Transport\Bridges\Http\HttpResponseTransformer;
use Quantum\Transport\Contracts\ResponseTransportManagerInterface;
use Quantum\Transport\Contracts\TransportAdapterInterface;
use Quantum\Transport\Contracts\TransportEmitterInterface;
use Quantum\Transport\Contracts\TransportKernelInterface;
use Quantum\Transport\Emitters\HttpSapiEmitter;
use Quantum\Transport\Emitters\NullTransportEmitter;
use Quantum\Transport\ResponseTransportManager;
use Quantum\Routing\PipelineArtifactStore;
use Quantum\Routing\Router;
use Quantum\Routing\TreeArtifactStore;
use Quantum\Routing\VersionArtifactStore;
use Quantum\Security\CsrfTokenManager;
use Quantum\Telemetry\Contracts\TelemetryExporterInterface;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Quantum\Telemetry\Engine\HttpTelemetryExporter;
use Quantum\Telemetry\Engine\InMemoryTelemetryExporter;
use Quantum\Telemetry\Engine\JsonLineTelemetryExporter;
use Quantum\Telemetry\Engine\NullTelemetryExporter;
use Quantum\Telemetry\Engine\TelemetryManager;
use Quantum\Controllers\Security\Context\ControllerSecurityContextFactory;
use Quantum\Controllers\Security\Contracts\ControllerSecurityContextFactoryInterface;
use Quantum\Controllers\Security\Contracts\ControllerSecurityDecisionEngineInterface;
use Quantum\Controllers\Security\Contracts\ControllerSecurityManagerInterface;
use Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyRegistryInterface;
use Quantum\Controllers\Security\Engine\ControllerSecurityManager;
use Quantum\Controllers\Security\Policy\ControllerSecurityDecisionEngine;
use Quantum\Controllers\Security\Policy\ControllerSecurityPolicyRegistry;
use Quantum\Controllers\Security\Worker\ControllerWorkerDisposition;
use Quantum\Controllers\Security\Worker\HardenedControllerSecurityDecisionEngine;
use Quantum\Controllers\Security\Worker\PolicyEvaluationSandbox;
use Quantum\Controllers\Security\Policy\Composition\PolicyBuilder;
use Quantum\Controllers\Security\Policy\Composition\PolicyExpressionResolver;
use Quantum\Validation\Validator;
use Quantum\View\Cache\CompiledViewStore;
use Quantum\View\Compilers\ViewCompiler;
use Quantum\View\Directives\DirectiveRegistry;
use Quantum\View\PhpViewEngine;
use Quantum\View\ViewFactory;
use VoltStack\Framework\Contracts\ExceptionHandler as ExceptionHandlerContract;
use VoltStack\Framework\Contracts\Kernel as KernelContract;
use VoltStack\Framework\Exceptions\ExceptionHandler;
use VoltStack\Runtime\Component\ComponentManager;
use VoltStack\Runtime\Component\InlinePageLoader;
use VoltStack\Runtime\Context\RuntimeContext;
use VoltStack\Runtime\Context\ScopeManager;
use VoltStack\Runtime\Context\WorkerLifecycle;
use VoltStack\Runtime\Hydration\Dehydrator;
use VoltStack\Runtime\Hydration\Hydrator;
use VoltStack\Runtime\Protocol\Checksum;
use VoltStack\Runtime\Protocol\FrontendRouteManifestController;
use VoltStack\Runtime\Protocol\ProtocolController;
use VoltStack\Runtime\Protocol\RuntimeAssetController;
use RuntimeException;

class Application extends Container
{
    protected static ?self $instance = null;

    /**
     * @var array<class-string<ServiceProvider>, ServiceProvider>
     */
    protected array $providers = [];

    protected bool $booted = false;

    /**
     * @var array<int, callable(self, RuntimeContext): void>
     */
    protected array $scopeStartingCallbacks = [];

    /**
     * @var array<int, callable(self, ?RuntimeContext): void>
     */
    protected array $scopeEndingCallbacks = [];

    public function __construct(protected string $basePath)
    {
        $this->basePath = rtrim($basePath, '\\/');

        static::setInstance($this);
        $this->registerBaseBindings();
    }

    public static function setInstance(self $app): void
    {
        static::$instance = $app;
    }

    public static function getInstance(): ?self
    {
        return static::$instance;
    }

    public function basePath(string $path = ''): string
    {
        return $this->joinPath($this->basePath, $path);
    }

    public function configPath(string $path = ''): string
    {
        return $this->joinPath($this->basePath('config'), $path);
    }

    public function resourcePath(string $path = ''): string
    {
        return $this->joinPath($this->basePath('resources'), $path);
    }

    public function storagePath(string $path = ''): string
    {
        return $this->joinPath($this->basePath('storage'), $path);
    }

    public function cachePath(string $path = ''): string
    {
        return $this->joinPath($this->storagePath('framework/cache'), $path);
    }

    public function viewPath(string $path = ''): string
    {
        return $this->joinPath($this->resourcePath('views'), $path);
    }

    public function registerBaseBindings(): void
    {
        $this->instance(self::class, $this);
        $this->instance(Container::class, $this);
        $this->instance(ContainerInterface::class, $this);
        $this->instance('path.base', $this->basePath);
        $this->instance('path.resources', $this->resourcePath());
        $this->instance('path.storage', $this->storagePath());
        $this->instance('path.cache', $this->cachePath());
        $this->instance('path.views', $this->viewPath());

        if (! isset($this->instances[ConfigRepository::class])) {
            $this->instance(ConfigRepository::class, new ConfigRepository());
        }

        if (! isset($this->bindings[Request::class])) {
            $this->scoped(Request::class, function (): Request {
                $context = RuntimeContext::current();

                if ($context === null) {
                    throw new RuntimeException('No active runtime context is available for the current request.');
                }

                return $context->request();
            });
        }

        if (! isset($this->bindings[RuntimeContext::class])) {
            $this->scoped(RuntimeContext::class, function (): RuntimeContext {
                $context = RuntimeContext::current();

                if ($context === null) {
                    throw new RuntimeException('No active runtime context is available.');
                }

                return $context;
            });
        }

        if (! isset($this->bindings[PhpViewEngine::class])) {
            $this->singleton(PhpViewEngine::class, fn(Application $app) => new PhpViewEngine(
                $app->make(CompiledViewStore::class),
            ));
        }

        if (! isset($this->bindings[DirectiveRegistry::class])) {
            $this->singleton(DirectiveRegistry::class);
        }

        if (! isset($this->bindings[ViewCompiler::class])) {
            $this->singleton(ViewCompiler::class, fn(Application $app) => new ViewCompiler(
                $app->make(DirectiveRegistry::class),
            ));
        }

        if (! isset($this->bindings[CompiledViewStore::class])) {
            $this->singleton(CompiledViewStore::class, fn(Application $app) => new CompiledViewStore(
                $app->make(ViewCompiler::class),
                (string) $app->config('cache.compiled.views', $app->cachePath('compiled/views')),
            ));
        }

        if (! isset($this->bindings[ViewFactory::class])) {
            $this->singleton(ViewFactory::class, fn(Application $app) => new ViewFactory(
                $app->make(PhpViewEngine::class),
                [$app->viewPath()],
            ));
        }

        if (! isset($this->bindings[ResponseFactory::class])) {
            $this->singleton(ResponseFactory::class);
        }

        if (! isset($this->bindings[HtmlDocumentBootstrapper::class])) {
            $this->singleton(HtmlDocumentBootstrapper::class);
        }

        if (! isset($this->bindings[CacheManager::class])) {
            $this->singleton(CacheManager::class);
        }

        if (! isset($this->bindings[CacheRepository::class])) {
            $this->singleton(CacheRepository::class, fn(Application $app) => $app->make(CacheManager::class)->store());
        }

        if (! isset($this->bindings[Validator::class])) {
            $this->singleton(Validator::class);
        }

        if (! isset($this->bindings[CsrfTokenManager::class])) {
            $this->singleton(CsrfTokenManager::class, fn(Application $app) => new CsrfTokenManager($app));
        }

        if (! isset($this->bindings[AuthenticationContextAccessor::class])) {
            $this->scoped(AuthenticationContextAccessor::class);
        }

        if (! isset($this->bindings[IdentityProviderInterface::class])) {
            $this->scoped(IdentityProviderInterface::class, LocalIdentityProvider::class);
        }

        if (! isset($this->bindings[AuthenticatorInterface::class])) {
            $this->scoped(AuthenticatorInterface::class, PasswordAuthenticator::class);
        }

        if (! isset($this->bindings[AuthenticationSessionRepositoryInterface::class])) {
            $this->singleton(AuthenticationSessionRepositoryInterface::class, InMemoryAuthenticationSessionRepository::class);
        }

        if (! isset($this->bindings[SessionAuthenticator::class])) {
            $this->scoped(SessionAuthenticator::class);
        }

        if (! isset($this->bindings[AuthenticatorResolverInterface::class])) {
            $this->scoped(AuthenticatorResolverInterface::class, DefaultAuthenticatorResolver::class);
        }

        if (! isset($this->bindings[AuthenticationOrchestratorInterface::class])) {
            $this->scoped(AuthenticationOrchestratorInterface::class, AuthenticationOrchestrator::class);
        }

        if (! isset($this->bindings[AuthManager::class])) {
            $this->scoped(AuthManager::class, fn(Application $app) => new AuthManager(
                $app->make(AuthenticationContextAccessor::class),
                $app->make(AuthenticationOrchestratorInterface::class),
                $app->make(AuthenticationSessionRepositoryInterface::class),
                $app->make(ConfigRepository::class),
            ));
        }

        if (! isset($this->bindings[AuthenticationManagerInterface::class])) {
            $this->scoped(AuthenticationManagerInterface::class, fn(Application $app) => $app->make(AuthManager::class));
        }

        if (! isset($this->bindings[CsrfMiddleware::class])) {
            $this->singleton(CsrfMiddleware::class);
        }

        if (! isset($this->bindings[ValidateSignatureMiddleware::class])) {
            $this->singleton(ValidateSignatureMiddleware::class);
        }

        if (! isset($this->bindings[MiddlewareAliasRegistry::class])) {
            $this->singleton(MiddlewareAliasRegistry::class, function (): MiddlewareAliasRegistry {
                $registry = new MiddlewareAliasRegistry();
                $registry->alias('csrf', CsrfMiddleware::class);
                $registry->alias('signed', ValidateSignatureMiddleware::class);

                return $registry;
            });
        }

        if (! isset($this->bindings[ControllerInterceptorRegistry::class])) {
            $this->singleton(ControllerInterceptorRegistry::class);
        }

        if (! isset($this->bindings[ControllerInterceptorRegistryInterface::class])) {
            $this->singleton(
                ControllerInterceptorRegistryInterface::class,
                fn(Application $app) => $app->make(ControllerInterceptorRegistry::class),
            );
        }

        if (! isset($this->bindings[InterceptorConditionRegistry::class])) {
            $this->singleton(InterceptorConditionRegistry::class, function (Application $app): InterceptorConditionRegistry {
                $registry = new InterceptorConditionRegistry($app);
                $registry->register('environment', EnvironmentInterceptorCondition::class);
                $registry->register('http_method', HttpMethodInterceptorCondition::class);
                $registry->register('route_name', RouteNameInterceptorCondition::class);
                $registry->alias('get', 'http_method', 'GET');
                $registry->alias('post', 'http_method', 'POST');
                $registry->alias('put', 'http_method', 'PUT');
                $registry->alias('patch', 'http_method', 'PATCH');
                $registry->alias('delete', 'http_method', 'DELETE');

                return $registry;
            });
        }

        if (! isset($this->bindings[MetadataSchemaRegistry::class])) {
            $this->singleton(MetadataSchemaRegistry::class, function (): MetadataSchemaRegistry {
                $registry = new MetadataSchemaRegistry();
                $registry->register(new MetadataSchema(
                    key: 'controller.interceptors',
                    type: MetadataValueType::Array,
                    merge: MetadataMergeStrategy::Append,
                    defaultValue: [],
                ));
                $registry->register(new MetadataSchema(
                    key: 'parameter_aliases',
                    type: MetadataValueType::Array,
                    merge: MetadataMergeStrategy::Replace,
                    defaultValue: [],
                ));
                $registry->register(new MetadataSchema(
                    key: 'controller.lifecycle.mode',
                    type: MetadataValueType::String,
                    merge: MetadataMergeStrategy::Replace,
                    defaultValue: 'auto',
                ));
                $registry->register(new MetadataSchema(
                    key: 'controller.lifecycle.timeouts.enabled',
                    type: MetadataValueType::Bool,
                    merge: MetadataMergeStrategy::Replace,
                    defaultValue: true,
                ));
                $registry->register(new MetadataSchema(
                    key: 'controller.lifecycle.timeouts.default',
                    type: MetadataValueType::Float,
                    merge: MetadataMergeStrategy::Replace,
                    defaultValue: null,
                ));
                $registry->register(new MetadataSchema(
                    key: 'controller.compilation.enabled',
                    type: MetadataValueType::Bool,
                    merge: MetadataMergeStrategy::Replace,
                    defaultValue: false,
                ));
                $registry->register(new MetadataSchema(
                    key: 'controller.compilation.artifacts.format',
                    type: MetadataValueType::String,
                    merge: MetadataMergeStrategy::Replace,
                    defaultValue: 'php',
                ));

                return $registry;
            });
        }

        if (! isset($this->bindings[MetadataProviderRegistry::class])) {
            $this->singleton(MetadataProviderRegistry::class, function (Application $app): MetadataProviderRegistry {
                $registry = new MetadataProviderRegistry();
                $registry->register(new RouteMetadataProvider());
                $registry->register(new ConfigMetadataProvider($app));
                $registry->register(new AttributeMetadataProvider());
                $registry->register(new ReflectionMetadataProvider());
                $registry->register(new ConventionMetadataProvider());

                return $registry;
            });
        }

        if (! isset($this->bindings[MetadataProviderPipeline::class])) {
            $this->singleton(MetadataProviderPipeline::class, fn(Application $app) => new MetadataProviderPipeline(
                $app->make(MetadataProviderRegistry::class),
            ));
        }

        if (! isset($this->bindings[MetadataValueNormalizer::class])) {
            $this->singleton(MetadataValueNormalizer::class);
        }

        if (! isset($this->bindings[MetadataMerger::class])) {
            $this->singleton(MetadataMerger::class);
        }

        if (! isset($this->bindings[MetadataEngine::class])) {
            $this->singleton(MetadataEngine::class, fn(Application $app) => new MetadataEngine(
                $app->make(MetadataProviderPipeline::class),
                $app->make(MetadataSchemaRegistry::class),
                $app->make(MetadataValueNormalizer::class),
                $app->make(MetadataMerger::class),
            ));
        }

        if (! isset($this->bindings[MetadataEngineInterface::class])) {
            $this->singleton(MetadataEngineInterface::class, fn(Application $app) => $app->make(MetadataEngine::class));
        }

        if (! isset($this->bindings[ControllerMetadataResolver::class])) {
            $this->singleton(ControllerMetadataResolver::class);
        }

        if (! isset($this->bindings[ControllerMetadataResolverInterface::class])) {
            $this->singleton(
                ControllerMetadataResolverInterface::class,
                fn(Application $app) => $app->make(ControllerMetadataResolver::class),
            );
        }

        if (! isset($this->bindings[ControllerRuntimeResolver::class])) {
            $this->singleton(ControllerRuntimeResolver::class);
        }

        if (! isset($this->bindings[ControllerRuntimeResolverInterface::class])) {
            $this->singleton(
                ControllerRuntimeResolverInterface::class,
                fn(Application $app) => $app->make(ControllerRuntimeResolver::class),
            );
        }

        if (! isset($this->bindings[ControllerEventDispatcherInterface::class])) {
            $this->singleton(ControllerEventDispatcherInterface::class, function (Application $app): ControllerEventDispatcherInterface {
                $mode = $app->config('controller_observability.dispatcher', 'auto');

                if ($mode === 'null') {
                    return new NullControllerEventDispatcher();
                }

                if ($mode === 'in_memory') {
                    return new InMemoryControllerEventDispatcher();
                }

                if ($mode === 'jsonl') {
                    $path = $app->config('controller_observability.jsonl_path');

                    if (is_string($path) && trim($path) !== '') {
                        return new JsonLineControllerEventDispatcher(trim($path));
                    }

                    return new JsonLineControllerEventDispatcher(
                        $app->joinPath($app->storagePath('framework/logs'), 'controller-events.jsonl'),
                    );
                }

                if ($app->isProduction()) {
                    return new JsonLineControllerEventDispatcher(
                        $app->joinPath($app->storagePath('framework/logs'), 'controller-events.jsonl'),
                    );
                }

                return new InMemoryControllerEventDispatcher();
            });
        }

        if (! isset($this->bindings[ControllerObservabilityManager::class])) {
            $this->singleton(ControllerObservabilityManager::class);
        }

        if (! isset($this->bindings[ControllerObservabilityManagerInterface::class])) {
            $this->singleton(
                ControllerObservabilityManagerInterface::class,
                fn(Application $app) => $app->make(ControllerObservabilityManager::class),
            );
        }

        if (! isset($this->bindings[TelemetryExporterInterface::class])) {
            $this->singleton(TelemetryExporterInterface::class, function (Application $app): TelemetryExporterInterface {
                $mode = $app->config('telemetry.exporter', 'auto');

                if ($mode === 'null') {
                    return new NullTelemetryExporter();
                }

                if ($mode === 'in_memory') {
                    return new InMemoryTelemetryExporter();
                }

                if ($mode === 'jsonl') {
                    $path = $app->config('telemetry.jsonl_path');

                    if (is_string($path) && trim($path) !== '') {
                        return new JsonLineTelemetryExporter(trim($path));
                    }

                    return new JsonLineTelemetryExporter(
                        $app->joinPath($app->storagePath('framework/logs'), 'telemetry.jsonl'),
                    );
                }

                if ($mode === 'webhook') {
                    $endpoint = trim((string) $app->config('telemetry.webhook_url', ''));
                    if ($endpoint === '') {
                        throw new RuntimeException('Telemetry webhook exporter requires [telemetry.webhook_url].');
                    }

                    $headers = $app->config('telemetry.webhook_headers', []);
                    if (! is_array($headers)) {
                        $headers = [];
                    }

                    $normalizedHeaders = [];
                    foreach ($headers as $name => $value) {
                        $headerName = trim((string) $name);
                        $headerValue = trim((string) $value);
                        if ($headerName === '' || $headerValue === '') {
                            continue;
                        }

                        $normalizedHeaders[$headerName] = $headerValue;
                    }

                    return new HttpTelemetryExporter(
                        endpoint: $endpoint,
                        headers: $normalizedHeaders,
                        requestTimeoutMs: max(250, (int) $app->config('telemetry.webhook_timeout_ms', 2000)),
                    );
                }

                if ($app->isProduction()) {
                    return new JsonLineTelemetryExporter(
                        $app->joinPath($app->storagePath('framework/logs'), 'telemetry.jsonl'),
                    );
                }

                return new InMemoryTelemetryExporter();
            });
        }

        if (! isset($this->bindings[TelemetryManager::class])) {
            $this->singleton(TelemetryManager::class);
        }

        if (! isset($this->bindings[TelemetryManagerInterface::class])) {
            $this->singleton(
                TelemetryManagerInterface::class,
                fn(Application $app) => $app->make(TelemetryManager::class),
            );
        }

        if (! isset($this->bindings[BuildManifestInterface::class])) {
            $this->singleton(BuildManifestInterface::class, function (Application $app): BuildManifestInterface {
                $paths = $app->config('controller_compilation.paths', []);
                $root = is_array($paths) && isset($paths['root']) && is_string($paths['root'])
                    ? $paths['root']
                    : $app->joinPath($app->storagePath('framework'), 'controllers');

                return new BuildManifest(rtrim($root, '\\/'));
            });
        }

        if (! isset($this->bindings[ArtifactStoreInterface::class])) {
            $this->singleton(ArtifactStoreInterface::class, function (Application $app): ArtifactStoreInterface {
                $paths = $app->config('controller_compilation.paths', []);
                $root = is_array($paths) && isset($paths['root']) && is_string($paths['root'])
                    ? $paths['root']
                    : $app->joinPath($app->storagePath('framework'), 'controllers');

                $format = $app->config('controller_compilation.artifacts.format', 'php');

                return new ArtifactStore(
                    manifest: $app->make(BuildManifestInterface::class),
                    storageRoot: rtrim($root, '\\/'),
                    format: is_string($format) && trim($format) !== '' ? $format : 'php',
                );
            });
        }

        if (! isset($this->bindings[CompilerInterface::class])) {
            $this->singleton(CompilerInterface::class, Compiler::class);
        }

        if (! isset($this->bindings[CompiledControllerFactoryInterface::class])) {
            $this->singleton(CompiledControllerFactoryInterface::class, function (Application $app): CompiledControllerFactoryInterface {
                $cache = $app->config('controller_compilation.cache', []);
                $workerMax = 2048;

                if (
                    is_array($cache)
                    && isset($cache['worker'])
                    && is_array($cache['worker'])
                    && isset($cache['worker']['max_artifacts'])
                ) {
                    $configured = $cache['worker']['max_artifacts'];
                    if (is_int($configured) && $configured > 0) {
                        $workerMax = $configured;
                    }
                }

                return new CompiledControllerFactory(
                    store: $app->make(ArtifactStoreInterface::class),
                    maxWorkerArtifacts: $workerMax,
                );
            });
        }

        if (! isset($this->bindings[TransportAdapterInterface::class])) {
            $this->singleton(TransportAdapterInterface::class, HttpTransportAdapter::class);
        }

        if (! isset($this->bindings[TransportEmitterInterface::class])) {
            $this->singleton(TransportEmitterInterface::class, HttpSapiEmitter::class);
        }

        if (! isset($this->bindings[HttpResponseTransformer::class])) {
            $this->singleton(HttpResponseTransformer::class);
        }

        if (! isset($this->bindings[ResponseTransportManager::class])) {
            $this->singleton(ResponseTransportManager::class);
        }

        if (! isset($this->bindings[ResponseTransportManagerInterface::class])) {
            $this->singleton(
                ResponseTransportManagerInterface::class,
                fn(Application $app) => $app->make(ResponseTransportManager::class),
            );
        }

        if (! isset($this->bindings[TransportKernelInterface::class])) {
            $this->singleton(
                TransportKernelInterface::class,
                fn(Application $app) => $app->make(HttpKernelTransportKernel::class),
            );
        }

        if (! isset($this->bindings[Checksum::class])) {
            $this->singleton(Checksum::class, fn(Application $app) => new Checksum($app));
        }

        if (! isset($this->bindings[Dehydrator::class])) {
            $this->singleton(Dehydrator::class, fn(Application $app) => new Dehydrator(
                $app->make(Checksum::class),
            ));
        }

        if (! isset($this->bindings[Hydrator::class])) {
            $this->singleton(Hydrator::class, fn(Application $app) => new Hydrator(
                $app->make(Dehydrator::class),
            ));
        }

        if (! isset($this->bindings[ComponentManager::class])) {
            $this->singleton(ComponentManager::class, fn(Application $app) => new ComponentManager(
                $app,
                $app->make(Hydrator::class),
                $app->make(Dehydrator::class),
            ));
        }

        if (! isset($this->bindings[InlinePageLoader::class])) {
            $this->singleton(InlinePageLoader::class, function (Application $app): InlinePageLoader {
                $loader = new InlinePageLoader($app);
                $loader->register();

                return $loader;
            });
        }

        if (! isset($this->bindings[ScopeManager::class])) {
            $this->singleton(ScopeManager::class, fn(Application $app) => new ScopeManager($app));
        }

        if (! isset($this->bindings[WorkerLifecycle::class])) {
            $this->singleton(WorkerLifecycle::class);
        }

        if (! isset($this->bindings[QuantumExceptionHandlerInterface::class])) {
            $this->singleton(QuantumExceptionHandlerInterface::class, static function (Application $app): QuantumExceptionHandlerInterface {
                $handler = new QuantumExceptionHandler();
                try {
                    /** @var array<string, mixed> $errorResponsesConfig */
                    $errorResponsesConfig = $app->config('controller_security.error_responses', []);
                    if (! is_array($errorResponsesConfig)) {
                        $errorResponsesConfig = [];
                    }
                    $securityMapper = new \Quantum\Controllers\Security\Exceptions\ControllerSecurityExceptionMapper($errorResponsesConfig);
                    $handler->addMapper($securityMapper);
                } catch (\Throwable) {
                }

                // =========================================================
                // Bloque 20: Shutdown fallback handler para Fatal Errors /
                // E_PARSE / memory exhaustion que escapan al Throwable try/catch normal
                // del HttpKernel.
                // =========================================================
                $shutdownHandlerRegistered = &$GLOBALS['__voltstack_exceptionhandler_shutdown_registered'];
                if (!($shutdownHandlerRegistered ?? false)) {
                    $shutdownHandlerRegistered = true;
                    $debugMode = (bool) ($app->config('app.debug', false) === true
                        || $app->config('exceptions.debug', false) === true
                        || (\defined('APP_DEBUG') && APP_DEBUG === true));
                    register_shutdown_function(static function () use ($debugMode): void {
                        $last = error_get_last();
                        if ($last === null) {
                            return;
                        }
                        $fatalTypes = \E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR
                            | \E_USER_ERROR | \E_RECOVERABLE_ERROR | \E_ALL & ~(\E_WARNING | \E_NOTICE | \E_DEPRECATED | \E_STRICT);
                        if (($last['type'] & $fatalTypes) === 0) {
                            return;
                        }
                        if (headers_sent($file, $line)) {
                            // Si ya se enviaron headers no podemos emitir otro response.
                            return;
                        }
                        $errClass = match ($last['type']) {
                            \E_ERROR => 'FatalError',
                            \E_PARSE => 'ParseError',
                            \E_CORE_ERROR => 'CoreError',
                            \E_COMPILE_ERROR => 'CompileError',
                            \E_USER_ERROR => 'UserError',
                            \E_RECOVERABLE_ERROR => 'RecoverableError',
                            default => 'UnknownFatal',
                        };
                        $errorCode = match ($last['type']) {
                            \E_ERROR => 'runtime.fatal_error',
                            \E_PARSE => 'runtime.parse_error',
                            \E_CORE_ERROR => 'runtime.core_error',
                            \E_COMPILE_ERROR => 'runtime.compile_error',
                            \E_USER_ERROR => 'runtime.user_error',
                            \E_RECOVERABLE_ERROR => 'runtime.recoverable_error',
                            default => 'server.error',
                        };
                        $isJson = ($_SERVER['HTTP_ACCEPT'] ?? null) !== null
                            && str_contains(strtolower((string)$_SERVER['HTTP_ACCEPT']), 'application/json');
                        $isVolt = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? null) === 'VoltStack'
                            || ($_SERVER['HTTP_X_VOLT_NAVIGATE'] ?? null) === 'true';
                        $msgNoLeak = 'An unexpected error occurred while processing the request.';
                        $escapedMessage = htmlspecialchars($last['message'] ?? 'Unknown fatal', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $escapedFile = htmlspecialchars($last['file'] ?? 'unknown', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $escapedLine = (int)($last['line'] ?? 0);

                        if ($isVolt) {
                            header('Content-Type: application/json; charset=UTF-8', true, 500);
                            $payload = [
                                'error' => [
                                    'type' => 'runtime.' . $errClass,
                                    'kind' => 'fatal',
                                    'code' => $errorCode,
                                    'status' => 500,
                                    'message' => $debugMode ? ($last['message'] ?? 'Server Error') : 'Server Error',
                                ],
                            ];
                            echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            return;
                        }

                        if ($isJson) {
                            header('Content-Type: application/problem+json; charset=UTF-8', true, 500);
                            $payload = [
                                'title' => 'Internal Server Error',
                                'status' => 500,
                                'reason_code' => $errorCode,
                                'message' => $debugMode ? ($last['message'] ?? 'Server Error') : $msgNoLeak,
                            ];
                            if ($debugMode) {
                                $payload['_debug'] = [
                                    'error_type' => $errClass,
                                    'file' => $last['file'] ?? null,
                                    'line' => $last['line'] ?? null,
                                ];
                            }
                            echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                            return;
                        }

                        header('Content-Type: text/html; charset=UTF-8', true, 500);
                        header('X-Volt-Error-Code: ' . $errorCode, true);
                        $debugHtml = $debugMode
                            ? '<div style="margin-top:20px; padding:14px; background:#0b1220; border:1px solid #334155; border-radius:8px;">'
                            . '<p style="margin:0 0 8px 0;"><strong style="color:#fca5a5;">FATAL SHUTDOWN:</strong> <code style="color:#f87171;">' . $errClass . '</code></p>'
                            . '<p style="margin:0 0 8px 0;"><strong>Message:</strong> <code>' . $escapedMessage . '</code></p>'
                            . '<p style="margin:0 0 8px 0;"><strong>Location:</strong> <code>' . $escapedFile . ':' . $escapedLine . '</code></p>'
                            . '</div>'
                            : '';
                        echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="volt-document" content="reload"><title>Server Error</title>
<style>body{font-family:Arial,sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;}main{max-width:720px;margin:0 auto;background:#111827;border:1px solid #334155;border-radius:12px;padding:32px;}h1{margin-top:0;}code{background:#1e293b;padding:2px 6px;border-radius:4px;}</style>
</head><body data-volt-document="reload"><main><h1>Server Error</h1><p>{$msgNoLeak}</p>{$debugHtml}</main></body></html>
HTML;
                    });
                }

                return $handler;
            });
        }

        if (! isset($this->bindings[ExceptionHandler::class])) {
            $this->singleton(ExceptionHandler::class);
        }

        if (! isset($this->bindings[ExceptionHandlerContract::class])) {
            $this->singleton(ExceptionHandlerContract::class, fn(Application $app) => $app->make(ExceptionHandler::class));
        }

        if (! isset($this->bindings[Router::class])) {
            $this->singleton(Router::class, function (Application $app): Router {
                $router = new Router($app);
                $router->get('/_volt/runtime.js', RuntimeAssetController::class)->meta([
                    'context' => 'spa',
                    'transport' => 'internal',
                    'endpoint' => 'volt.runtime.asset',
                    'protocol' => 'volt',
                ]);
                $router->get('/_volt/routes-manifest.json', FrontendRouteManifestController::class)->meta([
                    'context' => 'spa',
                    'transport' => 'internal',
                    'endpoint' => 'volt.routes.manifest',
                    'protocol' => 'volt',
                ]);
                $router->post('/_volt/action', ProtocolController::class)->meta([
                    'context' => 'spa',
                    'transport' => 'internal',
                    'endpoint' => 'volt.protocol.action',
                    'protocol' => 'volt',
                ]);

                return $router;
            });
        }

        if (! isset($this->bindings[PipelineArtifactStore::class])) {
            $this->singleton(PipelineArtifactStore::class, fn(Application $app) => new PipelineArtifactStore($app));
        }

        if (! isset($this->bindings[CollectionArtifactStore::class])) {
            $this->singleton(CollectionArtifactStore::class, fn(Application $app) => new CollectionArtifactStore($app));
        }

        if (! isset($this->bindings[MetadataArtifactStore::class])) {
            $this->singleton(MetadataArtifactStore::class, fn(Application $app) => new MetadataArtifactStore($app));
        }

        if (! isset($this->bindings[FrontendRouteManifestStore::class])) {
            $this->singleton(FrontendRouteManifestStore::class, fn(Application $app) => new FrontendRouteManifestStore($app));
        }

        if (! isset($this->bindings[SpaNavigationPayloadFactory::class])) {
            $this->singleton(SpaNavigationPayloadFactory::class);
        }

        if (! isset($this->bindings[TreeArtifactStore::class])) {
            $this->singleton(TreeArtifactStore::class, fn(Application $app) => new TreeArtifactStore($app));
        }

        if (! isset($this->bindings[VersionArtifactStore::class])) {
            $this->singleton(VersionArtifactStore::class, fn(Application $app) => new VersionArtifactStore($app));
        }

        if (! isset($this->bindings[ResponseNormalizer::class])) {
            $this->singleton(ResponseNormalizer::class);
        }

        if (! isset($this->bindings[HttpKernel::class])) {
            $this->singleton(HttpKernel::class, fn(Application $app) => new HttpKernel(
                $app,
                $app->make(Router::class),
                $app->make(ResponseNormalizer::class),
            ));
        }

        if (! isset($this->bindings[KernelContract::class])) {
            $this->singleton(KernelContract::class, fn(Application $app) => $app->make(HttpKernel::class));
        }

        if (! isset($this->bindings[ControllerSecurityPolicyRegistryInterface::class])) {
            $this->singleton(ControllerSecurityPolicyRegistryInterface::class, function (Application $app): ControllerSecurityPolicyRegistryInterface {
                $resolver = PolicyExpressionResolver::default();
                try {
                    $compositionCfg = $app->config('controller_security.composition', null);
                    if (!is_array($compositionCfg) || !($compositionCfg['enabled'] ?? true) || !($compositionCfg['use_expression_parser'] ?? true)) {
                        $resolver = null;
                    }
                } catch (\Throwable) {
                }
                $registry = new ControllerSecurityPolicyRegistry($resolver);
                $policiesConfig = $app->config('controller_security.policies', null);
                if (is_array($policiesConfig)) {
                    foreach ($policiesConfig as $policyClassOrInstance) {
                        if (is_string($policyClassOrInstance) && $policyClassOrInstance !== '' && class_exists($policyClassOrInstance)) {
                            try {
                                $registry->registerClass($policyClassOrInstance, static function () use ($app, $policyClassOrInstance) {
                                    return $app->make($policyClassOrInstance);
                                });
                            } catch (\Throwable) {
                            }
                        } elseif (is_string($policyClassOrInstance) && $policyClassOrInstance !== '') {
                            try {
                                $registry->registerExpression($policyClassOrInstance);
                            } catch (\Throwable) {
                            }
                        } elseif (is_object($policyClassOrInstance) && $policyClassOrInstance instanceof \Quantum\Controllers\Security\Contracts\ControllerSecurityPolicyInterface) {
                            $registry->register($policyClassOrInstance);
                        }
                    }
                }

                return $registry;
            });
        }

        if (! isset($this->bindings[PolicyExpressionResolver::class])) {
            $this->singleton(PolicyExpressionResolver::class, static function (): PolicyExpressionResolver {
                return PolicyExpressionResolver::default();
            });
        }

        if (! isset($this->bindings[PolicyBuilder::class])) {
            $this->bind(PolicyBuilder::class, static function (Application $app): PolicyBuilder {
                return PolicyBuilder::create($app->make(PolicyExpressionResolver::class));
            });
        }

        if (! isset($this->bindings[ControllerSecurityContextFactoryInterface::class])) {
            $this->singleton(ControllerSecurityContextFactoryInterface::class, function (Application $app): ControllerSecurityContextFactoryInterface {
                $max = $app->config('controller_security.authorization.max_policy_evaluations', 64);
                $max = is_numeric($max) ? (int) $max : 64;

                return new ControllerSecurityContextFactory(max(1, $max));
            });
        }

        if (! isset($this->bindings[ControllerSecurityDecisionEngineInterface::class])) {
            $this->singleton(ControllerSecurityDecisionEngineInterface::class, function (Application $app): ControllerSecurityDecisionEngineInterface {
                try {
                    $workerConfig = $app->config('controller_security.workers', null);
                    $hardenedEnabled = is_array($workerConfig) && ($workerConfig['hardened_engine'] ?? true);
                } catch (\Throwable) {
                    $hardenedEnabled = true;
                }

                $registry = $app->make(ControllerSecurityPolicyRegistryInterface::class);

                if (! $hardenedEnabled) {
                    return new ControllerSecurityDecisionEngine(
                        registry: $registry,
                        app: $app,
                    );
                }

                $wCfg = is_array($workerConfig ?? null) ? $workerConfig : [];
                $perEvalMs = $wCfg['policy_timeout_ms'] ?? null;
                $perEvalNs = is_numeric($perEvalMs) && $perEvalMs > 0
                    ? (int) (((float) $perEvalMs) * 1e6)
                    : 25_000_000;
                $maxRecursion = isset($wCfg['max_recursion_depth']) && is_int($wCfg['max_recursion_depth']) && $wCfg['max_recursion_depth'] > 0
                    ? $wCfg['max_recursion_depth']
                    : 8;
                $cbThreshold = isset($wCfg['circuit_breaker_failures']) && is_int($wCfg['circuit_breaker_failures']) && $wCfg['circuit_breaker_failures'] > 0
                    ? $wCfg['circuit_breaker_failures']
                    : 5;
                $cbOpenSeconds = isset($wCfg['circuit_breaker_open_seconds']) && is_int($wCfg['circuit_breaker_open_seconds']) && $wCfg['circuit_breaker_open_seconds'] > 0
                    ? $wCfg['circuit_breaker_open_seconds']
                    : 30;

                $sandbox = new PolicyEvaluationSandbox(
                    perPolicyTimeoutNs: $perEvalNs,
                    maxRecursionDepth: $maxRecursion,
                    circuitBreakerThreshold: $cbThreshold,
                    circuitBreakerOpenSeconds: $cbOpenSeconds,
                );

                return new HardenedControllerSecurityDecisionEngine(
                    registry: $registry,
                    sandbox: $sandbox,
                    app: $app,
                );
            });
        }

        if (! isset($this->bindings[ControllerSecurityManagerInterface::class])) {
            $this->singleton(ControllerSecurityManagerInterface::class, function (Application $app): ControllerSecurityManagerInterface {
                return new ControllerSecurityManager(
                    contextFactory: $app->make(ControllerSecurityContextFactoryInterface::class),
                    decisionEngine: $app->make(ControllerSecurityDecisionEngineInterface::class),
                );
            });
        }

        $this->make(InlinePageLoader::class);
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        /** @var ConfigRepository $config */
        $config = $this->make(ConfigRepository::class);

        return $config->get($key, $default);
    }

    public function environment(): string
    {
        $environment = $this->config('app.env');

        if (! is_string($environment) || trim($environment) === '') {
            return 'local';
        }

        return strtolower(trim($environment));
    }

    public function isProduction(): bool
    {
        return $this->environment() === 'production';
    }

    public function isDevelopment(): bool
    {
        return in_array($this->environment(), ['local', 'development', 'dev'], true);
    }

    public function register(ServiceProvider|string $provider): ServiceProvider
    {
        if (is_string($provider)) {
            /** @var ServiceProvider $provider */
            $provider = $this->make($provider);
        }

        $className = $provider::class;

        if (isset($this->providers[$className])) {
            return $this->providers[$className];
        }

        $provider->register();
        $this->providers[$className] = $provider;

        if ($this->booted) {
            $provider->boot();
        }

        return $provider;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        foreach ($this->providers as $provider) {
            $provider->boot();
        }

        $this->booted = true;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * @param callable(self, RuntimeContext): void $callback
     */
    public function onScopeStart(callable $callback): void
    {
        $this->scopeStartingCallbacks[] = $callback;
    }

    public function fireScopeStart(RuntimeContext $context): void
    {
        foreach ($this->scopeStartingCallbacks as $callback) {
            $callback($this, $context);
        }
    }

    /**
     * @param callable(self, ?RuntimeContext): void $callback
     */
    public function onScopeEnd(callable $callback): void
    {
        $this->scopeEndingCallbacks[] = $callback;
    }

    public function fireScopeEnd(?RuntimeContext $context): void
    {
        foreach ($this->scopeEndingCallbacks as $callback) {
            $callback($this, $context);
        }
    }

    /**
     * @return array<class-string<ServiceProvider>, ServiceProvider>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    protected function joinPath(string $basePath, string $path = ''): string
    {
        if ($path === '') {
            return $basePath;
        }

        return $basePath . DIRECTORY_SEPARATOR . ltrim($path, '\\/');
    }
}
