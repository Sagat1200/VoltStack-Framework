<?php

declare(strict_types=1);

namespace VoltStack\Framework;

use Quantum\Config\ConfigRepository;
use Quantum\Auth\AuthenticationServiceProvider;
use Quantum\Authorization\AuthorizationServiceProvider;
use Quantum\Authorization\Exceptions\AuthorizationExceptionMapper;
use Quantum\Database\Integration\DatabaseServiceProvider;
use Quantum\Auth\Exceptions\AuthExceptionMapper;
use Quantum\Cache\CacheManager;
use Quantum\Cache\Contracts\ClockInterface;
use Quantum\Cache\Contracts\MarshallerInterface;
use Quantum\Cache\Contracts\VersionAuthorityInterface;
use Quantum\Cache\LocalVersionAuthority;
use Quantum\Cache\Repository as CacheRepository;
use Quantum\Cache\PhpSerializeMarshaller;
use Quantum\Cache\SystemClock;
use Quantum\Bootstrap\Bootstrapper;
use Quantum\Bootstrap\Contracts\BootstrapperInterface;
use Quantum\Config\Bridge\ConfigAccessRegistry;
use Quantum\Config\Bridge\ConfigBridge;
use Quantum\Config\Bridge\FrameworkConfigAccessProfile;
use Quantum\Config\Diagnostics\ConfigRedactor;
use Quantum\Config\Diagnostics\ConfigStatusInspector;
use Quantum\Config\Publication\ConfigManifestStore;
use Quantum\Config\Publication\ConfigSnapshotCodec;
use Quantum\Config\Reference\ConfigReferenceResolver;
use Quantum\Config\Reference\EnvSecretValueResolver;
use Quantum\Config\Reference\SecretValueResolverInterface;
use Quantum\Config\Schema\Builtin\CacheConfigSchema;
use Quantum\Config\Schema\Builtin\DatabaseConfigSchema;
use Quantum\Config\Schema\Builtin\ExceptionsConfigSchema;
use Quantum\Config\Schema\ConfigSchemaRegistry;
use Quantum\Config\Scope\ConfigurationOverrideWriter;
use Quantum\Config\Scope\ConfigurationScopeRegistry;
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
use Quantum\Controllers\ControllerEngine;
use Quantum\Controllers\ControllerInvoker;
use Quantum\Controllers\ControllerResolver;
use Quantum\Controllers\Interceptors\ControllerInterceptorPipeline;
use Quantum\Controllers\ParameterResolutionEngine;
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
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityEvaluationRequest;
use Quantum\Controllers\Security\Engine\ControllerSecurityManager;
use Quantum\Controllers\Security\Policy\ControllerSecurityPolicy;
use Quantum\Controllers\Security\Policy\ControllerSecurityDecisionEngine;
use Quantum\Controllers\Security\Policy\ControllerSecurityPolicyRegistry;
use Quantum\Controllers\Security\Worker\ControllerWorkerDisposition;
use Quantum\Controllers\Security\Worker\HardenedControllerSecurityDecisionEngine;
use Quantum\Controllers\Security\Worker\PolicyEvaluationSandbox;
use Quantum\Controllers\Security\Policy\Composition\PolicyBuilder;
use Quantum\Controllers\Security\Policy\Composition\PolicyExpressionResolver;
use Quantum\Validation\Validator;
use Quantum\Exceptions\Bridges\CompositeTransportMapper;
use Quantum\Exceptions\Bridges\Console\CliTransportMapper;
use Quantum\Exceptions\Bridges\Http\HttpTransportMapper;
use Quantum\Exceptions\Bridges\TransportExceptionRenderer;
use Quantum\Exceptions\Compilation\ExceptionCompilationPlan;
use Quantum\Exceptions\Compilation\ExceptionCompilationException;
use Quantum\Exceptions\Compilation\ExceptionPlanCompiler;
use Quantum\Exceptions\Compilation\ExceptionPlanStore;
use Quantum\Exceptions\Bridges\Runtime\ExceptionRuntimeBridge;
use Quantum\Exceptions\Bridges\Runtime\ManagedExceptionRuntimeBridge;
use Quantum\Exceptions\Contracts\ExceptionManagerInterface;
use Quantum\Exceptions\Contracts\ExceptionNormalizerInterface;
use Quantum\Exceptions\Contracts\ExceptionRendererInterface;
use Quantum\Exceptions\Contracts\SemanticExceptionMapperInterface;
use Quantum\Exceptions\Contracts\TransportMapperInterface;
use Quantum\Exceptions\Core\ExceptionManager;
use Quantum\Exceptions\Mapping\DeterministicSemanticExceptionMapper;
use Quantum\Exceptions\Normalization\ThrowableNormalizer;
use Quantum\Exceptions\Reporting\EventDispatcherExceptionReporter;
use Quantum\Exceptions\Reporting\ExceptionReporterPipeline;
use Quantum\Exceptions\Reporting\ExceptionReportingPolicy;
use Quantum\Exceptions\Reporting\StructuredErrorLogReporter;
use Quantum\Exceptions\Reporting\TelemetryExceptionReporter;
use Quantum\Exceptions\Runtime\ExceptionRuntimeLimits;
use Quantum\Exceptions\Runtime\ExceptionScopeLifecycleManager;
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
use VoltStack\Runtime\Contracts\WorkerFactoryInterface;
use VoltStack\Runtime\Context\ScopeManager;
use VoltStack\Runtime\Context\WorkerLifecycle;
use VoltStack\Runtime\RequestRunner;
use VoltStack\Runtime\Reset\ResetManager;
use VoltStack\Runtime\RuntimeManager;
use VoltStack\Runtime\RuntimeManagerServer;
use VoltStack\Runtime\WorkerFactory;
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

        if (! isset($this->instances[ConfigAccessRegistry::class])) {
            $this->instance(ConfigAccessRegistry::class, FrameworkConfigAccessProfile::build());
        }

        if (! isset($this->instances[ConfigBridge::class])) {
            $bridge = new ConfigBridge($this->make(ConfigAccessRegistry::class));
            $this->instance(ConfigBridge::class, $bridge);

            /** @var ConfigRepository $config */
            $config = $this->make(ConfigRepository::class);
            $config->onMutation(static function (ConfigRepository $repository) use ($bridge): void {
                $bridge->sync($repository);
            });
            $bridge->sync($config);
        }

        if (! isset($this->bindings[SecretValueResolverInterface::class])) {
            $this->singleton(SecretValueResolverInterface::class, fn() => new EnvSecretValueResolver());
        }

        if (! isset($this->bindings[ConfigReferenceResolver::class])) {
            $this->singleton(ConfigReferenceResolver::class, fn(Application $app) => new ConfigReferenceResolver(
                $app->make(SecretValueResolverInterface::class),
            ));
        }

        if (! isset($this->bindings[ConfigSchemaRegistry::class])) {
            $this->singleton(ConfigSchemaRegistry::class, static function (): ConfigSchemaRegistry {
                $registry = new ConfigSchemaRegistry();
                $registry->register(CacheConfigSchema::build());
                $registry->register(DatabaseConfigSchema::build());
                $registry->register(ExceptionsConfigSchema::build());

                return $registry;
            });
        }

        if (! isset($this->bindings[ConfigRedactor::class])) {
            $this->singleton(ConfigRedactor::class, fn() => new ConfigRedactor());
        }

        if (! isset($this->bindings[ConfigStatusInspector::class])) {
            $this->singleton(ConfigStatusInspector::class, fn(Application $app) => new ConfigStatusInspector(
                redactor: $app->make(ConfigRedactor::class),
                codec: $app->make(ConfigSnapshotCodec::class),
                manifestStore: $app->make(ConfigManifestStore::class),
                scopeRegistry: $app->make(ConfigurationScopeRegistry::class),
            ));
        }

        if (! isset($this->bindings[ConfigSnapshotCodec::class])) {
            $this->singleton(ConfigSnapshotCodec::class, fn() => new ConfigSnapshotCodec());
        }

        if (! isset($this->bindings[ConfigManifestStore::class])) {
            $this->singleton(ConfigManifestStore::class, fn(Application $app) => new ConfigManifestStore(
                manifest: new BuildManifest($app->joinPath($app->storagePath('framework'), 'config')),
                storageRoot: $app->joinPath($app->storagePath('framework'), 'config'),
                codec: $app->make(ConfigSnapshotCodec::class),
            ));
        }

        if (! isset($this->bindings[ConfigurationScopeRegistry::class])) {
            $this->singleton(ConfigurationScopeRegistry::class, fn(Application $app) => new ConfigurationScopeRegistry(
                $app,
                $app->make(ConfigRepository::class),
            ));

            $this->make(ConfigBridge::class)->useScopeRegistry($this->make(ConfigurationScopeRegistry::class));
        }

        if (! isset($this->bindings[ConfigurationOverrideWriter::class])) {
            $this->singleton(ConfigurationOverrideWriter::class, fn(Application $app) => new ConfigurationOverrideWriter(
                $app->make(ConfigurationScopeRegistry::class),
            ));
        }

        if (! isset($this->bindings[BootstrapperInterface::class])) {
            $this->singleton(BootstrapperInterface::class, fn(Application $app) => new Bootstrapper($app));
        }

        if (! isset($this->bindings[Request::class])) {
            $this->scopedFor(Request::class, function (): Request {
                $context = RuntimeContext::current();

                if ($context === null) {
                    throw new RuntimeException('No active runtime context is available for the current request.');
                }

                return $context->request();
            });
        }

        if (! isset($this->bindings[RuntimeContext::class])) {
            $this->scopedFor(RuntimeContext::class, function (): RuntimeContext {
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
                (string) $app->make(ConfigBridge::class)->static('cache.compiled.views', $app->cachePath('compiled/views')),
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

        if (! isset($this->bindings[ClockInterface::class])) {
            $this->singleton(ClockInterface::class, SystemClock::class);
        }

        if (! isset($this->bindings[MarshallerInterface::class])) {
            $this->singleton(MarshallerInterface::class, PhpSerializeMarshaller::class);
        }

        if (! isset($this->bindings[VersionAuthorityInterface::class])) {
            $this->singleton(VersionAuthorityInterface::class, LocalVersionAuthority::class);
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

        if (! isset($this->providers[AuthenticationServiceProvider::class])) {
            $this->register(AuthenticationServiceProvider::class);
        }

        if (! isset($this->providers[AuthorizationServiceProvider::class])) {
            $this->register(AuthorizationServiceProvider::class);
        }

        if (! isset($this->providers[DatabaseServiceProvider::class])) {
            $this->register(DatabaseServiceProvider::class);
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
                $bridge = $app->make(ConfigBridge::class);
                $mode = $bridge->static('controller_observability.dispatcher', 'auto');

                if ($mode === 'null') {
                    return new NullControllerEventDispatcher();
                }

                if ($mode === 'in_memory') {
                    return new InMemoryControllerEventDispatcher();
                }

                if ($mode === 'jsonl') {
                    $path = $bridge->static('controller_observability.jsonl_path');

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
                $bridge = $app->make(ConfigBridge::class);
                $mode = $bridge->static('telemetry.exporter', 'auto');

                if ($mode === 'null') {
                    return new NullTelemetryExporter();
                }

                if ($mode === 'in_memory') {
                    return new InMemoryTelemetryExporter();
                }

                if ($mode === 'jsonl') {
                    $path = $bridge->static('telemetry.jsonl_path');

                    if (is_string($path) && trim($path) !== '') {
                        return new JsonLineTelemetryExporter(trim($path));
                    }

                    return new JsonLineTelemetryExporter(
                        $app->joinPath($app->storagePath('framework/logs'), 'telemetry.jsonl'),
                    );
                }

                if ($mode === 'webhook') {
                    $endpoint = trim((string) $bridge->static('telemetry.webhook_url', ''));
                    if ($endpoint === '') {
                        throw new RuntimeException('Telemetry webhook exporter requires [telemetry.webhook_url].');
                    }

                    $headers = $bridge->static('telemetry.webhook_headers', []);
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
                        requestTimeoutMs: max(250, (int) $bridge->static('telemetry.webhook_timeout_ms', 2000)),
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
                $paths = $app->make(ConfigBridge::class)->static('controller_compilation.paths', []);
                $root = is_array($paths) && isset($paths['root']) && is_string($paths['root'])
                    ? $paths['root']
                    : $app->joinPath($app->storagePath('framework'), 'controllers');

                return new BuildManifest(rtrim($root, '\\/'));
            });
        }

        if (! isset($this->bindings[ArtifactStoreInterface::class])) {
            $this->singleton(ArtifactStoreInterface::class, function (Application $app): ArtifactStoreInterface {
                $bridge = $app->make(ConfigBridge::class);
                $paths = $bridge->static('controller_compilation.paths', []);
                $root = is_array($paths) && isset($paths['root']) && is_string($paths['root'])
                    ? $paths['root']
                    : $app->joinPath($app->storagePath('framework'), 'controllers');

                $format = $bridge->static('controller_compilation.artifacts.format', 'php');

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
                $cache = $app->make(ConfigBridge::class)->static('controller_compilation.cache', []);
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
            $this->scopedFor(ScopeManager::class, fn(Application $app) => new ScopeManager($app), 'worker');
        }

        if (! isset($this->bindings[WorkerLifecycle::class])) {
            $this->scopedFor(WorkerLifecycle::class, WorkerLifecycle::class, 'worker');
        }

        if (! isset($this->bindings[ResetManager::class])) {
            $this->scopedFor(ResetManager::class, ResetManager::class, 'worker');
        }

        if (! isset($this->bindings[ExceptionScopeLifecycleManager::class])) {
            $this->scopedFor(
                ExceptionScopeLifecycleManager::class,
                fn(Application $app) => new ExceptionScopeLifecycleManager(
                    limits: new ExceptionRuntimeLimits(
                        maxOccurrences: (int) (($app->make(ExceptionCompilationPlan::class)->config()['limits']['occurrences_per_scope'] ?? 128)),
                        maxHandlingDepth: (int) (($app->make(ExceptionCompilationPlan::class)->config()['limits']['handling_depth'] ?? 2)),
                    ),
                ),
                'worker',
            );
        }

        if (! isset($this->bindings[ExceptionRuntimeBridge::class])) {
            $this->scopedFor(
                ExceptionRuntimeBridge::class,
                fn(Application $app) => new ManagedExceptionRuntimeBridge(
                    app: $app,
                    lifecycleManager: $app->make(ExceptionScopeLifecycleManager::class),
                    resetManager: $app->make(ResetManager::class),
                ),
                'worker',
            );
        }

        if (! isset($this->bindings[RequestRunner::class])) {
            $this->scopedFor(RequestRunner::class, RequestRunner::class, 'worker');
        }

        $this->make(ResetManager::class)->register(function (Application $app): void {
            if (! $app->resolved(TelemetryExporterInterface::class)) {
                return;
            }

            $exporter = $app->make(TelemetryExporterInterface::class);

            if ($exporter instanceof InMemoryTelemetryExporter) {
                $exporter->clear();
            }
        });

        $this->make(ResetManager::class)->register(function (Application $app): void {
            if (! $app->resolved(ControllerEventDispatcherInterface::class)) {
                return;
            }

            $dispatcher = $app->make(ControllerEventDispatcherInterface::class);

            if ($dispatcher instanceof InMemoryControllerEventDispatcher) {
                $dispatcher->clear();
            }
        });

        $this->make(ResetManager::class)->register(function (Application $app): void {
            if (! $app->resolved(ConfigurationScopeRegistry::class)) {
                return;
            }

            $app->make(ConfigurationScopeRegistry::class)->clearCurrent();
        });

        if (! isset($this->bindings[WorkerFactoryInterface::class])) {
            $this->singleton(WorkerFactoryInterface::class, fn(Application $app) => new WorkerFactory($app));
        }

        if (! isset($this->bindings[RuntimeManager::class])) {
            $this->singleton(RuntimeManager::class, fn(Application $app) => RuntimeManager::createDefault($app));
        }

        if (! isset($this->bindings[RuntimeManagerServer::class])) {
            $this->singleton(RuntimeManagerServer::class, fn(Application $app) => new RuntimeManagerServer(
                app: $app,
                manager: $app->make(RuntimeManager::class),
            ));
        }

        if (! isset($this->bindings[ExceptionPlanCompiler::class])) {
            $this->singleton(ExceptionPlanCompiler::class, static fn(): ExceptionPlanCompiler => new ExceptionPlanCompiler());
        }

        if (! isset($this->bindings[ExceptionPlanStore::class])) {
            $this->singleton(ExceptionPlanStore::class, static fn(Application $app): ExceptionPlanStore => new ExceptionPlanStore(
                $app->storagePath('framework/exceptions'),
            ));
        }

        if (! isset($this->bindings[ExceptionCompilationPlan::class])) {
            $this->singleton(ExceptionCompilationPlan::class, static function (Application $app): ExceptionCompilationPlan {
                /** @var ConfigRepository $config */
                $config = $app->make(ConfigRepository::class);
                $rawConfig = $app->config('exceptions', []);
                $exceptionConfig = is_array($rawConfig) ? $rawConfig : [];

                if ($config->has('exceptions')) {
                    $environment = is_string($exceptionConfig['environment'] ?? null)
                        ? trim((string) $exceptionConfig['environment'])
                        : 'production';
                    $requiredInProduction = $exceptionConfig['compilation']['required_in_production'] ?? true;

                    if ($environment === 'production' && $requiredInProduction === true) {
                        $plan = $app->make(ExceptionPlanStore::class)->load();

                        if ($plan === null) {
                            throw new ExceptionCompilationException(sprintf(
                                'A published exception compilation plan is required in production at [%s].',
                                $app->make(ExceptionPlanStore::class)->currentPath(),
                            ));
                        }

                        $expectedRuntime = is_string($exceptionConfig['runtime'] ?? null)
                            ? trim((string) $exceptionConfig['runtime'])
                            : '';

                        if ($expectedRuntime === '') {
                            $expectedRuntime = PHP_SAPI === 'cli' ? 'sapi' : 'frankenphp';
                        }

                        $plan->assertCompatibleWith(
                            expectedRuntime: $expectedRuntime,
                            expectedPhpRuntimeVersion: PHP_VERSION,
                        );

                        return $plan;
                    }
                }

                return $app
                    ->make(ExceptionPlanCompiler::class)
                    ->compile($exceptionConfig);
            });
        }

        if (! isset($this->bindings[ExceptionNormalizerInterface::class])) {
            $this->singleton(ExceptionNormalizerInterface::class, static function (Application $app): ExceptionNormalizerInterface {
                $limits = $app->make(ExceptionCompilationPlan::class)->config()['limits'] ?? [];

                return new ThrowableNormalizer(
                    maxCauses: (int) ($limits['causes'] ?? 8),
                    maxFramesPerCause: (int) ($limits['frames_per_cause'] ?? 32),
                    messageBytes: (int) ($limits['message_bytes'] ?? 2048),
                    snapshotBytes: (int) ($limits['snapshot_bytes'] ?? 32768),
                    projectRoots: [$app->basePath()],
                );
            });
        }

        if (! isset($this->bindings[SemanticExceptionMapperInterface::class])) {
            $this->singleton(
                SemanticExceptionMapperInterface::class,
                static fn(): SemanticExceptionMapperInterface => DeterministicSemanticExceptionMapper::standard(),
            );
        }

        if (! isset($this->bindings[ExceptionReportingPolicy::class])) {
            $this->singleton(ExceptionReportingPolicy::class, static function (Application $app): ExceptionReportingPolicy {
                $compiledPlan = $app->make(ExceptionCompilationPlan::class);
                $reporting = $compiledPlan->config()['reporting'] ?? [];

                return new ExceptionReportingPolicy(
                    revision: $compiledPlan->policyRevision(),
                    ignoredCodes: is_array($reporting['ignore_codes'] ?? null) ? $reporting['ignore_codes'] : [],
                    sampleRate: (float) ($reporting['sample_rate'] ?? 1.0),
                );
            });
        }

        if (! isset($this->bindings[ExceptionReporterPipeline::class])) {
            $this->singleton(ExceptionReporterPipeline::class, static function (Application $app): ExceptionReporterPipeline {
                $reporting = $app->make(ExceptionCompilationPlan::class)->config()['reporting'] ?? [];
                $enabled = ($reporting['enabled'] ?? true) === true;
                $reporters = [];

                if ($enabled) {
                    foreach ((array) ($reporting['reporters'] ?? []) as $reporterId) {
                        if (! is_string($reporterId) || $reporterId === '') {
                            continue;
                        }

                        $reporter = match ($reporterId) {
                            'exceptions.events' => new EventDispatcherExceptionReporter(
                                $app->make(ControllerEventDispatcherInterface::class),
                            ),
                            'exceptions.telemetry' => new TelemetryExceptionReporter(
                                $app->make(TelemetryManagerInterface::class),
                            ),
                            'exceptions.log' => new StructuredErrorLogReporter(),
                            default => null,
                        };

                        if ($reporter !== null) {
                            $reporters[] = $reporter;
                        }
                    }
                }

                return new ExceptionReporterPipeline(
                    reporters: $reporters,
                    policy: $app->make(ExceptionReportingPolicy::class),
                );
            });
        }

        if (! isset($this->bindings[TransportMapperInterface::class])) {
            $this->singleton(TransportMapperInterface::class, static function (Application $app): TransportMapperInterface {
                $rendering = $app->make(ExceptionCompilationPlan::class)->config()['rendering'] ?? [];

                return new CompositeTransportMapper(
                    http: new HttpTransportMapper(
                        cacheControl: is_string($rendering['cache_control'] ?? null) ? $rendering['cache_control'] : 'no-store',
                        supportedSpaVersions: is_array($rendering['spa_versions'] ?? null) ? $rendering['spa_versions'] : [1],
                        apiFormat: is_string($rendering['api_format'] ?? null) ? $rendering['api_format'] : 'problem_json',
                        browserFormat: is_string($rendering['browser_format'] ?? null) ? $rendering['browser_format'] : 'html',
                    ),
                    cli: new CliTransportMapper(),
                );
            });
        }

        if (! isset($this->bindings[ExceptionRendererInterface::class])) {
            $this->singleton(
                ExceptionRendererInterface::class,
                static fn(): ExceptionRendererInterface => new TransportExceptionRenderer(),
            );
        }

        if (! isset($this->bindings[ExceptionManagerInterface::class])) {
            $this->singleton(ExceptionManagerInterface::class, static function (Application $app): ExceptionManagerInterface {
                return new ExceptionManager(
                    normalizer: $app->make(ExceptionNormalizerInterface::class),
                    semanticMapper: $app->make(SemanticExceptionMapperInterface::class),
                    reporterPipeline: $app->make(ExceptionReporterPipeline::class),
                    transportMapper: $app->make(TransportMapperInterface::class),
                    renderer: $app->make(ExceptionRendererInterface::class),
                );
            });
        }

        if (! isset($this->bindings[QuantumExceptionHandlerInterface::class])) {
            $this->singleton(QuantumExceptionHandlerInterface::class, static function (Application $app): QuantumExceptionHandlerInterface {
                $handler = new QuantumExceptionHandler();
                $exceptionPlan = $app->make(ExceptionCompilationPlan::class);
                try {
                    /** @var array<string, mixed> $errorResponsesConfig */
                    $errorResponsesConfig = $app->config('controller_security.error_responses', []);
                    if (! is_array($errorResponsesConfig)) {
                        $errorResponsesConfig = [];
                    }
                    $securityMapper = new \Quantum\Controllers\Security\Exceptions\ControllerSecurityExceptionMapper($errorResponsesConfig);
                    $handler->addMapper($securityMapper);
                    $handler->addMapper(new AuthExceptionMapper());
                    $handler->addMapper(new AuthorizationExceptionMapper());
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
                        || $exceptionPlan->debug()
                        || (\defined('APP_DEBUG') && constant('APP_DEBUG') === true));
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
                            ? '<div style="margin-block-start:20px; padding:14px; background:#0b1220; border:1px solid #334155; border-radius:8px;">'
                            . '<p style="margin:0 0 8px 0;"><strong style="color:#fca5a5;">FATAL SHUTDOWN:</strong> <code style="color:#f87171;">' . $errClass . '</code></p>'
                            . '<p style="margin:0 0 8px 0;"><strong>Message:</strong> <code>' . $escapedMessage . '</code></p>'
                            . '<p style="margin:0 0 8px 0;"><strong>Location:</strong> <code>' . $escapedFile . ':' . $escapedLine . '</code></p>'
                            . '</div>'
                            : '';
                        echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="volt-document" content="reload"><title>Server Error</title>
<style>body{font-family:Arial,sans-serif;background:#0f172a;color:#e2e8f0;padding:40px;}main{max-inline-size:720px;margin:0 auto;background:#111827;border:1px solid #334155;border-radius:12px;padding:32px;}h1{margin-block-start:0;}code{background:#1e293b;padding:2px 6px;border-radius:4px;}</style>
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
                    'security' => [
                        'exposed' => true,
                        'policies' => ['framework.allow.internal_volt_transport'],
                    ],
                ]);
                $router->get('/_volt/routes-manifest.json', FrontendRouteManifestController::class)->meta([
                    'context' => 'spa',
                    'transport' => 'internal',
                    'endpoint' => 'volt.routes.manifest',
                    'protocol' => 'volt',
                    'security' => [
                        'exposed' => true,
                        'policies' => ['framework.allow.internal_volt_transport'],
                    ],
                ]);
                $router->post('/_volt/action', ProtocolController::class)->meta([
                    'context' => 'spa',
                    'transport' => 'internal',
                    'endpoint' => 'volt.protocol.action',
                    'protocol' => 'volt',
                    'security' => [
                        'exposed' => true,
                        'policies' => ['framework.allow.internal_volt_transport'],
                    ],
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

        if (! isset($this->bindings[ControllerEngine::class])) {
            $this->bind(ControllerEngine::class, fn(Application $app) => new ControllerEngine(
                app: $app,
                resolver: $app->make(ControllerResolver::class),
                parameters: $app->make(ParameterResolutionEngine::class),
                missing: $app->make(\Quantum\Routing\Dispatching\MissingRouteHandler::class),
                invoker: $app->make(ControllerInvoker::class),
                interceptors: $app->make(ControllerInterceptorPipeline::class),
                runtime: $app->make(ControllerRuntimeResolverInterface::class),
                observability: $app->make(ControllerObservabilityManagerInterface::class),
                normalizer: $app->make(ResponseNormalizer::class),
                compiledFactory: $app->make(CompiledControllerFactoryInterface::class),
                securityManager: null,
                authorizationContextFactory: $app->has(\Quantum\Authorization\Contracts\AuthorizationContextFactoryInterface::class)
                    ? $app->make(\Quantum\Authorization\Contracts\AuthorizationContextFactoryInterface::class)
                    : null,
                authorizationManager: $app->has(\Quantum\Authorization\Contracts\AuthorizationManagerInterface::class)
                    ? $app->make(\Quantum\Authorization\Contracts\AuthorizationManagerInterface::class)
                    : null,
                authorizationMetadataResolver: $app->has(\Quantum\Authorization\Contracts\AuthorizationMetadataResolverInterface::class)
                    ? $app->make(\Quantum\Authorization\Contracts\AuthorizationMetadataResolverInterface::class)
                    : null,
            ));
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
                $registry->register(new class extends ControllerSecurityPolicy {
                    public function id(): string
                    {
                        return 'framework.allow.internal_volt_transport';
                    }

                    public function evaluate(SecurityEvaluationRequest $request): SecurityDecision
                    {
                        return SecurityDecision::allow(
                            policyId: $this->id(),
                            reasonCode: 'internal_volt_transport_allow',
                            obligations: ['scope' => 'framework.internal.volt'],
                        );
                    }
                });
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
            $this->scopedFor(ControllerSecurityContextFactoryInterface::class, function (Application $app): ControllerSecurityContextFactoryInterface {
                $max = $app->make(ConfigBridge::class)->scoped('controller_security.authorization.max_policy_evaluations', 64);
                $max = is_numeric($max) ? (int) $max : 64;
                $authManager = null;

                try {
                    $authManager = $app->make(\Quantum\Auth\Contracts\AuthenticationManagerInterface::class);
                } catch (\Throwable) {
                    $authManager = null;
                }

                $bearerTokenService = null;

                try {
                    $bearerTokenService = $app->make(\Quantum\Auth\Tokens\BearerTokenService::class);
                } catch (\Throwable) {
                    $bearerTokenService = null;
                }

                return new ControllerSecurityContextFactory(max(1, $max), $authManager, $bearerTokenService);
            }, 'request');
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
            $this->scopedFor(ControllerSecurityManagerInterface::class, function (Application $app): ControllerSecurityManagerInterface {
                return new ControllerSecurityManager(
                    contextFactory: $app->make(ControllerSecurityContextFactoryInterface::class),
                    decisionEngine: $app->make(ControllerSecurityDecisionEngineInterface::class),
                );
            }, 'request');
        }

        $this->make(InlinePageLoader::class);
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        /** @var ConfigRepository $config */
        $config = $this->make(ConfigRepository::class);

        return $config->get($key, $default);
    }

    public function configBridge(): ConfigBridge
    {
        return $this->make(ConfigBridge::class);
    }

    public function configWriter(): ConfigurationOverrideWriter
    {
        return $this->make(ConfigurationOverrideWriter::class);
    }

    public function configStatusInspector(): ConfigStatusInspector
    {
        return $this->make(ConfigStatusInspector::class);
    }

    public function configSnapshotCodec(): ConfigSnapshotCodec
    {
        return $this->make(ConfigSnapshotCodec::class);
    }

    public function configManifestStore(): ConfigManifestStore
    {
        return $this->make(ConfigManifestStore::class);
    }

    public function environment(): string
    {
        $environment = $this->configBridge()->static('app.env', $this->config('app.env'));

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
            /** @var string $provider */
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

    public function leaveScope(): void
    {
        $scopeId = $this->hasActiveScope() ? $this->currentScopeId() : null;

        parent::leaveScope();

        if ($scopeId === null || ! $this->resolved(ConfigurationScopeRegistry::class)) {
            return;
        }

        $this->make(ConfigurationScopeRegistry::class)->endScope($scopeId);
    }

    protected function joinPath(string $basePath, string $path = ''): string
    {
        if ($path === '') {
            return $basePath;
        }

        return $basePath . DIRECTORY_SEPARATOR . ltrim($path, '\\/');
    }
}
