<?php

declare(strict_types=1);

namespace VoltStack\Framework;

use Quantum\Config\ConfigRepository;
use Quantum\Auth\AuthManager;
use Quantum\Cache\CacheManager;
use Quantum\Cache\Repository as CacheRepository;
use Quantum\Controllers\Interceptors\ControllerInterceptorRegistry;
use Quantum\Controllers\Interceptors\Conditions\EnvironmentInterceptorCondition;
use Quantum\Controllers\Interceptors\Conditions\HttpMethodInterceptorCondition;
use Quantum\Controllers\Interceptors\Conditions\InterceptorConditionRegistry;
use Quantum\Controllers\Interceptors\Conditions\RouteNameInterceptorCondition;
use Quantum\Controllers\Interceptors\Contracts\ControllerInterceptorRegistryInterface;
use Quantum\Controllers\Metadata\ControllerMetadataResolver;
use Quantum\Controllers\Metadata\ControllerMetadataResolverInterface;
use Quantum\Container\Container;
use Quantum\Container\Contracts\ContainerInterface;
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
use Quantum\Metadata\Providers\ReflectionMetadataProvider;
use Quantum\Metadata\Providers\RouteMetadataProvider;
use Quantum\Metadata\Schema\MetadataSchema;
use Quantum\Metadata\Schema\MetadataSchemaRegistry;
use Quantum\Middlewares\CsrfMiddleware;
use Quantum\Routing\PipelineArtifactStore;
use Quantum\Routing\Router;
use Quantum\Routing\TreeArtifactStore;
use Quantum\Routing\VersionArtifactStore;
use Quantum\Security\CsrfTokenManager;
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

        if (! isset($this->bindings[AuthManager::class])) {
            $this->scoped(AuthManager::class);
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

                return $registry;
            });
        }

        if (! isset($this->bindings[MetadataProviderRegistry::class])) {
            $this->singleton(MetadataProviderRegistry::class, function (): MetadataProviderRegistry {
                $registry = new MetadataProviderRegistry();
                $registry->register(new RouteMetadataProvider());
                $registry->register(new AttributeMetadataProvider());
                $registry->register(new ReflectionMetadataProvider());

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