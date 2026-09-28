<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Console\Commands\AuthorizationManifestClearCommand;
use Quantum\Authorization\Console\Commands\AuthorizationManifestCompileCommand;
use Quantum\Authorization\Contracts\AuthorizationMetadataResolverInterface;
use Quantum\Authorization\Manifest\Contracts\AuthorizationManifestStoreInterface;
use Quantum\Authorization\Metadata\AuthorizationMetadata;
use Quantum\Authorization\Metadata\AuthorizationMetadataPayload;
use Quantum\Authorization\Metadata\AuthorizationRequirement;
use Quantum\Console\Input;
use Quantum\Console\Output;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Routing\Route;
use Quantum\Routing\RouteCollection;
use Quantum\Routing\RouteDefinition;
use Quantum\Routing\RouteMatch;
use VoltStack\Framework\Application;

final class AuthorizationManifestCommandsTest extends TestCase
{
    private string $tempBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('volt_authz_cmd_', true);
        @mkdir($this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap', 0o777, true);
    }

    protected function tearDown(): void
    {
        $GLOBALS['__volt_authz_test_app'] = null;
        unset($GLOBALS['__volt_authz_test_app']);

        $this->removeDirectory($this->tempBasePath);

        parent::tearDown();
    }

    public function test_compile_command_metadata_matches_expectations(): void
    {
        $command = new AuthorizationManifestCompileCommand($this->tempBasePath);

        self::assertSame('authz:manifest:compile', $command->name());
        self::assertSame('Authorization', $command->category());
        self::assertNotEmpty($command->description());
        self::assertContains('authorization:manifest:compile', $command->aliases());
        self::assertContains('authz:compile-manifest', $command->aliases());
    }

    public function test_clear_command_metadata_matches_expectations(): void
    {
        $command = new AuthorizationManifestClearCommand($this->tempBasePath);

        self::assertSame('authz:manifest:clear', $command->name());
        self::assertSame('Authorization', $command->category());
        self::assertNotEmpty($command->description());
        self::assertContains('authorization:manifest:clear', $command->aliases());
        self::assertContains('authz:clear-manifest', $command->aliases());
    }

    public function test_compile_empty_routes_returns_zero_and_exits_successfully(): void
    {
        $store = self::createStoreSpy();
        $app = self::buildApp(
            routes: self::buildRouteCollection([]),
            resolver: self::createResolverStub(),
            store: $store,
        );
        $this->writeBootstrap($app);

        $command = new AuthorizationManifestCompileCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(
            Input::fromArgv(['volt', 'authz:manifest:compile']),
            $output,
        );

        self::assertSame(0, $exit);
        self::assertFalse($store->wasCleared);
        self::assertCount(0, $store->written);
        self::assertStringContainsString('No se encontraron rutas', $this->stdout($output));
    }

    public function test_compile_persists_metadata_with_fingerprint_into_store(): void
    {
        $requirement = new AuthorizationRequirement('posts:view');
        $metadata = new AuthorizationMetadata(false, [$requirement], 'fp_posts_view');

        $routes = self::buildRouteCollection([
            new Route(RouteDefinition::make(['GET'], '/posts', [self::class, 'noop'])),
        ]);

        $store = self::createStoreSpy();
        $app = self::buildApp(
            routes: $routes,
            resolver: self::createResolverStub($metadata),
            store: $store,
        );
        $this->writeBootstrap($app);

        $command = new AuthorizationManifestCompileCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(
            Input::fromArgv(['volt', 'authz:manifest:compile']),
            $output,
        );

        self::assertSame(0, $exit);
        self::assertStringContainsString('Manifest de autorizacion compilado correctamente', $this->stdout($output));
        self::assertMatchesRegularExpression('/Rutas compiladas:\s+1/', $this->stdout($output));
    }

    public function test_compile_dry_run_does_not_persist_store(): void
    {
        $metadata = new AuthorizationMetadata(
            false,
            [new AuthorizationRequirement('posts:view')],
            'fp_posts_view',
        );

        $routes = self::buildRouteCollection([
            new Route(RouteDefinition::make(['GET'], '/posts', [self::class, 'noop'])),
        ]);

        $store = self::createStoreSpy();
        $app = self::buildApp(
            routes: $routes,
            resolver: self::createResolverStub($metadata),
            store: $store,
        );
        $this->writeBootstrap($app);

        $command = new AuthorizationManifestCompileCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(
            Input::fromArgv(['volt', 'authz:manifest:compile', '--dry-run']),
            $output,
        );

        self::assertSame(0, $exit);
        self::assertCount(0, $store->written, 'Dry-run no debe escribir en store.');
        self::assertStringContainsString('[dry-run]', $this->stdout($output));
        self::assertStringContainsString('Manifest de autorizacion calculado', $this->stdout($output));
    }

    public function test_compile_verbose_reports_fingerprint_and_requirements(): void
    {
        $metadata = new AuthorizationMetadata(
            false,
            [
                new AuthorizationRequirement('posts:view'),
                new AuthorizationRequirement('posts:list'),
            ],
            'fp_posts_list',
        );

        $routes = self::buildRouteCollection([
            new Route(RouteDefinition::make(['GET'], '/posts', [self::class, 'noop'])),
        ]);

        $store = self::createStoreSpy();
        $app = self::buildApp(
            routes: $routes,
            resolver: self::createResolverStub($metadata),
            store: $store,
        );
        $this->writeBootstrap($app);

        $command = new AuthorizationManifestCompileCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(
            Input::fromArgv(['volt', 'authz:manifest:compile', '--verbose']),
            $output,
        );

        $out = $this->stdout($output);
        self::assertSame(0, $exit);
        self::assertStringContainsString('fingerprint: fp_posts_list', $out);
        self::assertStringContainsString('public: no', $out);
        self::assertStringContainsString('requirements: 2', $out);
    }

    public function test_compile_skip_when_resolver_throws_or_no_fingerprint(): void
    {
        $routes = self::buildRouteCollection([
            new Route(RouteDefinition::make(['GET'], '/no-fp', [self::class, 'noop'])),
            new Route(RouteDefinition::make(['GET'], '/throws', [self::class, 'noop'])),
        ]);

        $resolver = self::createResolverStubCallbacks([
            // /no-fp: metadata sin fingerprint (una sola llamada)
            static function (RouteMatch $match): AuthorizationMetadata {
                return new AuthorizationMetadata(false, [], null);
            },
            // /throws: exception
            static function (): never {
                throw new \RuntimeException('boom resolver');
            },
        ]);

        $store = self::createStoreSpy();
        $app = self::buildApp(
            routes: $routes,
            resolver: $resolver,
            store: $store,
        );
        $this->writeBootstrap($app);

        $command = new AuthorizationManifestCompileCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(
            Input::fromArgv(['volt', 'authz:manifest:compile', '--verbose']),
            $output,
        );

        $out = $this->stdout($output);
        self::assertSame(0, $exit);
        self::assertMatchesRegularExpression('/Rutas compiladas:\s+0/', $out);
        self::assertStringContainsString('[SKIP]', $out);
        self::assertStringContainsString('boom resolver', $out);
    }

    public function test_clear_removes_entries_and_reports_count(): void
    {
        $store = self::createStoreSpy();
        $store->clearReturnCount = 7;
        $app = self::buildApp(
            routes: self::buildRouteCollection([]),
            resolver: self::createResolverStub(),
            store: $store,
        );
        $this->writeBootstrap($app);

        $command = new AuthorizationManifestClearCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(
            Input::fromArgv(['volt', 'authz:manifest:clear']),
            $output,
        );

        $out = $this->stdout($output);
        self::assertSame(0, $exit);
        self::assertTrue($store->wasCleared);
        self::assertStringContainsString('Manifest de autorizacion limpiado correctamente', $out);
        self::assertMatchesRegularExpression('/Entries eliminadas:\s+7/', $out);
    }

    public function test_clear_reports_zero_entries_when_store_empty(): void
    {
        $store = self::createStoreSpy();
        $store->clearReturnCount = 0;
        $app = self::buildApp(
            routes: self::buildRouteCollection([]),
            resolver: self::createResolverStub(),
            store: $store,
        );
        $this->writeBootstrap($app);

        $command = new AuthorizationManifestClearCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(
            Input::fromArgv(['volt', 'authz:manifest:clear']),
            $output,
        );

        self::assertSame(0, $exit);
        self::assertTrue($store->wasCleared);
        self::assertStringContainsString('No habia entries en el manifest', $this->stdout($output));
    }

    public function test_clear_dry_run_does_not_touch_store_and_reports_class(): void
    {
        $store = self::createStoreSpy();
        $store->clearReturnCount = 5;
        $app = self::buildApp(
            routes: self::buildRouteCollection([]),
            resolver: self::createResolverStub(),
            store: $store,
        );
        $this->writeBootstrap($app);

        $command = new AuthorizationManifestClearCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(
            Input::fromArgv(['volt', 'authz:manifest:clear', '--dry-run']),
            $output,
        );

        $out = $this->stdout($output);
        self::assertSame(0, $exit);
        self::assertFalse($store->wasCleared, 'Dry-run no debe invocar clear().');
        self::assertStringContainsString('[dry-run]', $out);
        self::assertStringContainsString('eliminaria', $out);
        self::assertStringContainsString('Store activo:', $out);
    }

    public function test_clear_verbose_reports_store_class(): void
    {
        $store = self::createStoreSpy();
        $store->clearReturnCount = 2;
        $app = self::buildApp(
            routes: self::buildRouteCollection([]),
            resolver: self::createResolverStub(),
            store: $store,
        );
        $this->writeBootstrap($app);

        $command = new AuthorizationManifestClearCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(
            Input::fromArgv(['volt', 'authz:manifest:clear', '--verbose']),
            $output,
        );

        $out = $this->stdout($output);
        self::assertSame(0, $exit);
        self::assertStringContainsString('Store utilizado:', $out);
    }

    public function test_clear_with_exception_returns_non_zero_and_error_message(): void
    {
        $store = self::createStoreSpy();
        $store->clearException = new \RuntimeException('can not clear');
        $app = self::buildApp(
            routes: self::buildRouteCollection([]),
            resolver: self::createResolverStub(),
            store: $store,
        );
        $this->writeBootstrap($app);

        $command = new AuthorizationManifestClearCommand($this->tempBasePath);
        $output = new Output();
        $exit = $command->handle(
            Input::fromArgv(['volt', 'authz:manifest:clear']),
            $output,
        );

        self::assertSame(1, $exit);
        self::assertStringContainsString('can not clear', $this->stderr($output));
    }

    public static function noop(): void
    {
    }

    private function writeBootstrap(Application $app): void
    {
        $GLOBALS['__volt_authz_test_app'] = $app;
        $file = $this->tempBasePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';
        file_put_contents(
            $file,
            '<?php return $GLOBALS[' . "'" . '__volt_authz_test_app' . "'" . '];' . PHP_EOL,
        );
    }

    /**
     * @return array{written: list<AuthorizationMetadataPayload>, wasCleared: bool, clearReturnCount: int, clearException: ?\Throwable}
     */
    private static function createStoreSpy(): object
    {
        return new class implements AuthorizationManifestStoreInterface {
            /** @var list<AuthorizationMetadataPayload> */
            public array $written = [];
            public bool $wasCleared = false;
            public int $clearReturnCount = 0;
            public ?\Throwable $clearException = null;

            public function has(string $fingerprint): bool
            {
                return false;
            }

            public function get(string $fingerprint): ?AuthorizationMetadataPayload
            {
                return null;
            }

            public function put(AuthorizationMetadataPayload $payload): void
            {
                $this->written[] = $payload;
            }

            public function forget(string $fingerprint): void
            {
            }

            public function clear(): int
            {
                if ($this->clearException !== null) {
                    throw $this->clearException;
                }

                $this->wasCleared = true;

                return $this->clearReturnCount;
            }
        };
    }

    private static function createResolverStub(?AuthorizationMetadata $default = null): AuthorizationMetadataResolverInterface
    {
        return new class($default) implements AuthorizationMetadataResolverInterface {
            public function __construct(private readonly ?AuthorizationMetadata $default) {}

            public function resolve(RouteMatch $match, ?ControllerDefinition $definition = null): AuthorizationMetadata
            {
                return $this->default ?? new AuthorizationMetadata(false, [], null);
            }
        };
    }

    /**
     * @param array<int, callable(RouteMatch, ?ControllerDefinition): AuthorizationMetadata> $callbacks
     */
    private static function createResolverStubCallbacks(array $callbacks): AuthorizationMetadataResolverInterface
    {
        return new class($callbacks) implements AuthorizationMetadataResolverInterface {
            /** @var array<int, callable> */
            private array $callbacks;
            private int $index = 0;

            public function __construct(array $callbacks)
            {
                $this->callbacks = array_values($callbacks);
            }

            public function resolve(RouteMatch $match, ?ControllerDefinition $definition = null): AuthorizationMetadata
            {
                $cb = $this->callbacks[$this->index] ?? throw new \RuntimeException('No hay mas callbacks');
                $this->index++;

                return ($cb)($match, $definition);
            }
        };
    }

    private static function buildApp(
        RouteCollection $routes,
        AuthorizationMetadataResolverInterface $resolver,
        AuthorizationManifestStoreInterface $store,
    ): Application {
        $basePath = sys_get_temp_dir();
        $app = new Application($basePath);

        $app->instance(RouteCollection::class, $routes);
        $app->instance(AuthorizationMetadataResolverInterface::class, $resolver);
        $app->instance(AuthorizationManifestStoreInterface::class, $store);

        return $app;
    }

    /**
     * @param array<int, Route> $routes
     */
    private static function buildRouteCollection(array $routes): RouteCollection
    {
        $collection = new RouteCollection();

        foreach ($routes as $route) {
            $collection->add($route);
        }

        return $collection;
    }

    private function stdout(Output $output): string
    {
        $r = new \ReflectionProperty($output, 'stdoutBuffer');

        return (string) $r->getValue($output);
    }

    private function stderr(Output $output): string
    {
        $r = new \ReflectionProperty($output, 'stderrBuffer');

        return (string) $r->getValue($output);
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
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
