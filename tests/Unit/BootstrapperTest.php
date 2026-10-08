<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\Bootstrapper;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use Quantum\Config\ConfigRepository;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\HttpKernel\HttpKernel;
use Quantum\HttpKernel\Contracts\MiddlewareInterface;
use Quantum\Routing\CollectionArtifactStore;
use Quantum\Routing\FrontendRouteManifestStore;
use Quantum\Routing\MetadataArtifactStore;
use Quantum\Routing\PipelineArtifactStore;
use Quantum\Routing\Router;
use Quantum\Routing\TreeArtifactStore;
use Quantum\Routing\VersionArtifactStore;
use VoltStack\Framework\Application;

final class BootstrapperTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-bootstrapper-' . uniqid('', true);

        if (! mkdir($concurrentDirectory = $this->basePath, 0777, true) && ! is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create test directory [%s].', $this->basePath));
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_loads_route_files_when_live_route_registration_is_required(): void
    {
        $app = new Application($this->basePath);
        $bootstrapper = new Bootstrapper($app);

        $bootstrapper->loadRoutes($this->writeRouteFile('route.file', '/from-route-file'));

        $route = $app->make(Router::class)->collection()->named('route.file');

        self::assertNotNull($route);
        self::assertSame('/from-route-file', $route->uri());
    }

    public function test_it_can_load_route_files_using_the_route_facade_without_injecting_the_router_argument(): void
    {
        $app = new Application($this->basePath);
        $bootstrapper = new Bootstrapper($app);

        $bootstrapper->loadRoutes($this->writeFacadeRouteFile('route.facade', '/from-route-facade'));

        $route = $app->make(Router::class)->collection()->named('route.facade');

        self::assertNotNull($route);
        self::assertSame('/from-route-facade', $route->uri());
    }

    public function test_it_can_load_route_files_using_fluent_group_builders_through_the_route_facade(): void
    {
        $app = new Application($this->basePath);
        $bootstrapper = new Bootstrapper($app);

        $bootstrapper->loadRoutes($this->writeFacadeGroupedRouteFile());

        $route = $app->make(Router::class)->collection()->named('admin.users.index');

        self::assertNotNull($route);
        self::assertSame('/admin/users', $route->uri());
        self::assertSame('admin.example.com', $route->routeDomain());
    }

    public function test_it_skips_route_file_interpretation_when_compiled_route_artifacts_are_available(): void
    {
        $builderApp = new Application($this->basePath);
        $builderApp->make(ConfigRepository::class)->set('app.env', 'production');
        $builderRouter = $builderApp->make(Router::class);
        $builderRouter->get('/from-artifact', TestBootstrapController::class . '@show')->name('artifact.route');
        $builderApp->make(CollectionArtifactStore::class)->compileAndWrite($builderRouter);

        $runtimeApp = new Application($this->basePath);
        $runtimeApp->make(ConfigRepository::class)->set('app.env', 'production');
        $bootstrapper = new Bootstrapper($runtimeApp);
        $bootstrapper->loadRoutes($this->writeRouteFile('route.file', '/from-route-file'));

        $runtimeRouter = $runtimeApp->make(Router::class);

        self::assertNull($runtimeRouter->collection()->named('route.file'));
        self::assertNull($runtimeRouter->compiledCollection()->named('route.file'));
        self::assertSame('/from-artifact', $runtimeRouter->compiledCollection()->named('artifact.route')?->uri());
    }

    public function test_it_can_boot_a_fresh_runtime_instance_using_route_artifacts_without_manual_reload(): void
    {
        $builderApp = new Application($this->basePath);
        /** @var ConfigRepository $builderConfig */
        $builderConfig = $builderApp->make(ConfigRepository::class);
        $builderConfig->set('app.env', 'production');

        $builderRouter = $builderApp->make(Router::class);
        $builderRouter->post('/from-runtime-artifact/{id}', TestBootstrapPayloadController::class . '@show')
            ->name('artifact.runtime')
            ->middleware(TestBootstrapHeaderMiddleware::class)
            ->meta([
                'auth' => 'session',
                'runtime' => ['mode' => 'spa'],
            ]);

        $builderApp->make(CollectionArtifactStore::class)->compileAndWrite($builderRouter);
        $builderApp->make(TreeArtifactStore::class)->compileAndWrite($builderRouter);
        $builderApp->make(MetadataArtifactStore::class)->compileAndWrite($builderRouter);
        $builderApp->make(PipelineArtifactStore::class)->compileAndWrite($builderRouter);
        $this->clearCollectionArtifactMetadata('artifact.runtime');
        $builderApp->make(VersionArtifactStore::class)->compileAndWrite($builderRouter);

        $runtimeApp = new Application($this->basePath);
        /** @var ConfigRepository $runtimeConfig */
        $runtimeConfig = $runtimeApp->make(ConfigRepository::class);
        $runtimeConfig->set('app.env', 'production');

        $bootstrapper = new Bootstrapper($runtimeApp);
        $bootstrapper->loadRoutes($this->writeRouteFile('route.file', '/from-route-file'));

        $runtimeRouter = $runtimeApp->make(Router::class);
        $compiledRoute = $runtimeRouter->compiledCollection()->named('artifact.runtime');

        self::assertNull($runtimeRouter->collection()->named('route.file'));
        self::assertNull($runtimeRouter->compiledCollection()->named('route.file'));
        self::assertNotNull($compiledRoute);
        self::assertSame('session', $compiledRoute->routeMetadata()->get('auth'));
        self::assertSame(['mode' => 'spa'], $compiledRoute->routeMetadata()->get('runtime'));

        $resolvedPipeline = $runtimeRouter->resolvedRoutePipeline($compiledRoute);

        self::assertSame($compiledRoute->routePipeline(), $resolvedPipeline);
        self::assertSame($compiledRoute->routePipeline()->id(), $resolvedPipeline->id());

        $response = $runtimeApp->make(HttpKernel::class)->handle(Request::create('/from-runtime-artifact/42', 'POST'));
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode());
        self::assertSame('passed', $response->headers()['X-Bootstrap-Middleware'] ?? null);
        self::assertSame('42', $payload['id']);
        self::assertSame('session', $payload['auth']);
        self::assertSame(['mode' => 'spa'], $payload['runtime']);
    }

    public function test_it_falls_back_to_live_route_loading_when_the_runtime_artifact_manifest_is_invalid(): void
    {
        $builderApp = new Application($this->basePath);
        /** @var ConfigRepository $builderConfig */
        $builderConfig = $builderApp->make(ConfigRepository::class);
        $builderConfig->set('app.env', 'production');

        $builderRouter = $builderApp->make(Router::class);
        $builderRouter->get('/from-artifact', TestBootstrapController::class . '@show')->name('artifact.route');

        $builderApp->make(CollectionArtifactStore::class)->compileAndWrite($builderRouter);
        $builderApp->make(TreeArtifactStore::class)->compileAndWrite($builderRouter);
        $builderApp->make(MetadataArtifactStore::class)->compileAndWrite($builderRouter);
        $builderApp->make(PipelineArtifactStore::class)->compileAndWrite($builderRouter);
        $builderApp->make(VersionArtifactStore::class)->compileAndWrite($builderRouter);

        file_put_contents(
            $builderApp->make(CollectionArtifactStore::class)->path(),
            "<?php\n\nreturn ['version' => 999, 'routes' => []];\n",
        );

        $runtimeApp = new Application($this->basePath);
        /** @var ConfigRepository $runtimeConfig */
        $runtimeConfig = $runtimeApp->make(ConfigRepository::class);
        $runtimeConfig->set('app.env', 'production');

        $bootstrapper = new Bootstrapper($runtimeApp);
        $bootstrapper->loadRoutes($this->writeRouteFile('route.file', '/from-route-file'));

        $runtimeRouter = $runtimeApp->make(Router::class);

        self::assertNotNull($runtimeRouter->collection()->named('route.file'));
        self::assertSame('/from-route-file', $runtimeRouter->compiledCollection()->named('route.file')?->uri());
        self::assertNull($runtimeRouter->compiledCollection()->named('artifact.route'));
    }

    public function test_it_invalidates_the_frontend_route_manifest_in_development(): void
    {
        $builderApp = new Application($this->basePath);
        $builderApp->make(ConfigRepository::class)->set('app.env', 'local');
        $builderRouter = $builderApp->make(Router::class);
        $builderRouter->get('/from-artifact', TestBootstrapController::class . '@show')->name('artifact.route');

        $builderApp->make(CollectionArtifactStore::class)->compileAndWrite($builderRouter);
        $builderApp->make(TreeArtifactStore::class)->compileAndWrite($builderRouter);
        $builderApp->make(MetadataArtifactStore::class)->compileAndWrite($builderRouter);
        $builderApp->make(PipelineArtifactStore::class)->compileAndWrite($builderRouter);
        $builderApp->make(VersionArtifactStore::class)->compileAndWrite($builderRouter);
        $manifestPath = $builderApp->make(FrontendRouteManifestStore::class)->compileAndWrite($builderRouter);

        self::assertFileExists($manifestPath);

        $runtimeApp = new Application($this->basePath);
        $runtimeApp->make(ConfigRepository::class)->set('app.env', 'local');
        $bootstrapper = new Bootstrapper($runtimeApp);
        $bootstrapper->loadRoutes($this->writeRouteFile('route.file', '/from-route-file'));

        self::assertFileDoesNotExist($manifestPath);
        self::assertSame('/from-route-file', $runtimeApp->make(Router::class)->collection()->named('route.file')?->uri());
    }

    public function test_it_prefers_the_active_published_configuration_snapshot_over_live_config_files(): void
    {
        $builderApp = new Application($this->basePath);
        $builderConfig = $builderApp->make(ConfigRepository::class);
        $builderConfig->set('app.name', 'Published Config');
        $builderConfig->set('app.env', 'production');
        $builderConfig->set('app.key', 'published-secret');

        $codec = $builderApp->configSnapshotCodec();
        $baseSnapshot = $builderConfig->snapshot(provenance: [
            'app' => 'published',
        ]);
        $artifact = $builderApp->configManifestStore()->publish($builderConfig->snapshot(
            provenance: $baseSnapshot->provenance(),
            configId: $codec->configId($baseSnapshot),
        ));
        $builderApp->configManifestStore()->activateGeneration($artifact->generationId());

        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';
        mkdir($configPath, 0777, true);
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

return [
    'name' => 'Live Config',
    'env' => 'local',
    'key' => 'live-secret',
];
PHP
        );

        $runtimeApp = new Application($this->basePath);
        $bootstrapper = new Bootstrapper($runtimeApp);
        $bootstrapper->loadConfiguration();

        $runtimeConfig = $runtimeApp->make(ConfigRepository::class);

        self::assertSame('Published Config', $runtimeConfig->get('app.name'));
        self::assertSame('production', $runtimeConfig->get('app.env'));
        self::assertSame('published-secret', $runtimeConfig->get('app.key'));
        self::assertSame(['app' => 'published'], $runtimeConfig->provenance());
    }

    public function test_it_falls_back_to_live_config_files_when_no_published_configuration_is_active(): void
    {
        $configPath = $this->basePath . DIRECTORY_SEPARATOR . 'config';
        mkdir($configPath, 0777, true);
        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'app.php',
            <<<'PHP'
<?php

return [
    'name' => 'Live Config',
    'env' => 'local',
    'key' => 'live-secret',
];
PHP
        );

        $runtimeApp = new Application($this->basePath);
        $bootstrapper = new Bootstrapper($runtimeApp);
        $bootstrapper->loadConfiguration();

        $runtimeConfig = $runtimeApp->make(ConfigRepository::class);

        self::assertSame('Live Config', $runtimeConfig->get('app.name'));
        self::assertSame('local', $runtimeConfig->get('app.env'));
        self::assertSame('live-secret', $runtimeConfig->get('app.key'));
        self::assertSame($configPath . DIRECTORY_SEPARATOR . 'app.php', $runtimeConfig->provenance()['app']);
    }

    public function test_it_can_require_an_active_published_configuration_snapshot_explicitly(): void
    {
        $builderApp = new Application($this->basePath);
        $builderConfig = $builderApp->make(ConfigRepository::class);
        $builderConfig->set('app.name', 'Published Config');
        $builderConfig->set('app.env', 'production');

        $codec = $builderApp->configSnapshotCodec();
        $baseSnapshot = $builderConfig->snapshot(provenance: [
            'app' => 'published',
        ]);
        $artifact = $builderApp->configManifestStore()->publish($builderConfig->snapshot(
            provenance: $baseSnapshot->provenance(),
            configId: $codec->configId($baseSnapshot),
        ));
        $builderApp->configManifestStore()->activateGeneration($artifact->generationId());

        $runtimeApp = new Application($this->basePath);
        $bootstrapper = new Bootstrapper($runtimeApp);
        $bootstrapper->loadPublishedConfiguration();

        $runtimeConfig = $runtimeApp->make(ConfigRepository::class);

        self::assertSame('Published Config', $runtimeConfig->get('app.name'));
        self::assertSame('production', $runtimeConfig->get('app.env'));
        self::assertSame(['app' => 'published'], $runtimeConfig->provenance());
    }

    public function test_it_throws_when_published_configuration_is_required_but_missing(): void
    {
        $runtimeApp = new Application($this->basePath);
        $bootstrapper = new Bootstrapper($runtimeApp);

        $this->expectException(PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('Published configuration is required for bootstrap');

        $bootstrapper->loadPublishedConfiguration();
    }

    private function writeRouteFile(string $name, string $uri): string
    {
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'routes.php';
        $action = var_export(TestBootstrapController::class . '@show', true);
        $routeName = var_export($name, true);
        $routeUri = var_export($uri, true);
        $contents = <<<PHP
<?php

declare(strict_types=1);

use Quantum\Routing\Router;

return static function (Router \$router): void {
    \$router->get({$routeUri}, {$action})->name({$routeName});
};
PHP;

        file_put_contents($path, $contents);

        return $path;
    }

    private function writeFacadeRouteFile(string $name, string $uri): string
    {
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'routes-facade.php';
        $action = var_export(TestBootstrapController::class . '@show', true);
        $routeName = var_export($name, true);
        $routeUri = var_export($uri, true);
        $contents = <<<PHP
<?php

declare(strict_types=1);

use Quantum\Facades\Route;

return static function (): void {
    Route::get({$routeUri}, {$action})->name({$routeName});
};
PHP;

        file_put_contents($path, $contents);

        return $path;
    }

    private function writeFacadeGroupedRouteFile(): string
    {
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'routes-facade-group.php';
        $action = var_export(TestBootstrapController::class . '@show', true);
        $contents = <<<PHP
<?php

declare(strict_types=1);

use Quantum\Facades\Route;

return static function (): void {
    Route::prefix('/admin')
        ->name('admin')
        ->domain('admin.example.com')
        ->group(static function (): void {
            Route::get('/users', {$action})->name('users.index');
        });
};
PHP;

        file_put_contents($path, $contents);

        return $path;
    }

    private function clearCollectionArtifactMetadata(string $routeName): void
    {
        $path = $this->basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'collection.php';
        /** @var array{version: int, routes: array<int, array<string, mixed>>} $payload */
        $payload = require $path;

        foreach ($payload['routes'] as $index => $route) {
            if (($route['name'] ?? null) !== $routeName) {
                continue;
            }

            $payload['routes'][$index]['metadata'] = [];
        }

        file_put_contents($path, "<?php\n\nreturn " . var_export($payload, true) . ";\n");
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}

final class TestBootstrapController
{
    public function show(): string
    {
        return 'ok';
    }
}

final class TestBootstrapPayloadController
{
    public function show(Request $request, string $id): Response
    {
        return new Response(json_encode([
            'id' => $id,
            'auth' => $request->routeMeta('auth'),
            'runtime' => $request->routeMeta('runtime'),
        ], JSON_THROW_ON_ERROR), 200, [
            'Content-Type' => 'application/json',
        ]);
    }
}

final class TestBootstrapHeaderMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if ($response instanceof Response) {
            $response->header('X-Bootstrap-Middleware', 'passed');
        }

        return $response;
    }
}
