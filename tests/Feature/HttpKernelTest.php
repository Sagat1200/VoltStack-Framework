<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use DateInterval;
use DateTimeImmutable;
use Quantum\Config\ConfigRepository;
use Quantum\Actions\Action;
use Quantum\Http\RedirectResponse;
use Quantum\Http\Request;
use Quantum\Http\Response;
use Quantum\HttpKernel\Contracts\MiddlewareInterface;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Attributes\Delete;
use Quantum\Routing\Attributes\Domain;
use Quantum\Routing\Attributes\Get;
use Quantum\Routing\Attributes\Middleware;
use Quantum\Routing\Attributes\Name;
use Quantum\Routing\Attributes\Patch;
use Quantum\Routing\Attributes\Post;
use Quantum\Routing\Attributes\Put;
use Quantum\Routing\CollectionArtifactStore;
use Quantum\Routing\Contracts\RouteBindableInterface;
use Quantum\Routing\Exceptions\DuplicateRouteException;
use Quantum\Routing\Exceptions\DuplicateRouteNameException;
use Quantum\Routing\Exceptions\RouteUrlGenerationException;
use Quantum\Routing\MetadataArtifactStore;
use Quantum\Routing\PipelineArtifactStore;
use Quantum\Routing\Router;
use Quantum\Routing\TreeArtifactStore;
use Quantum\Routing\VersionArtifactStore;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Component\Component;

final class HttpKernelTest extends TestCase
{
    private string $basePath;
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-http-kernel-' . uniqid('', true);

        if (! mkdir($concurrentDirectory = $this->basePath, 0777, true) && ! is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Unable to create test directory [%s].', $this->basePath));
        }

        $this->app = new Application($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_dispatches_a_closure_route_and_returns_a_response(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/', fn() => 'VoltStack Home');

        $kernel = $this->app->make(HttpKernel::class);
        $response = $kernel->handle(Request::create('/'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('VoltStack Home', $response->content());
    }

    public function test_it_registers_and_resolves_a_static_get_route(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/health', fn() => 'ok')->name('health');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/health'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('ok', $response->content());
    }

    public function test_it_registers_and_resolves_a_dynamic_get_route(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/users/{id}', fn(string $id) => 'user:' . $id)
            ->name('users.show')
            ->whereNumber('id');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/users/42'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('user:42', $response->content());
    }

    public function test_it_registers_and_resolves_a_domain_specific_route(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/reports', fn() => 'tenant-reports')
            ->name('tenant.reports')
            ->domain('tenant.example.com');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/reports', 'GET', [], [], [], [], [], [
            'HTTP_HOST' => 'tenant.example.com',
        ]));

        self::assertSame(200, $response->statusCode());
        self::assertSame('tenant-reports', $response->content());
    }

    public function test_it_resolves_invokable_route_actions_and_route_parameters(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/users/{id}', TestInvokableController::class);

        $kernel = $this->app->make(HttpKernel::class);
        $response = $kernel->handle(Request::create('/users/42'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('application/json; charset=UTF-8', $response->headers()['Content-Type']);
        self::assertSame('{"id":"42","message":"resolved","path":"\\/users\\/42"}', $response->content());
    }

    public function test_it_dispatches_string_controller_actions(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/controller-string', TestStringController::class . '@show');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/controller-string'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('controller-string', $response->content());
    }

    public function test_it_dispatches_controller_routes_without_reading_php_attributes_in_live_runtime(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/controller-attributes-live/{id}', TestAttributedController::class . '@show');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/controller-attributes-live/42'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('attributes:42', $response->content());
    }

    public function test_it_registers_basic_http_routes_from_controller_attributes(): void
    {
        $router = $this->app->make(Router::class);
        $router->attributeRoutes(TestHttpAttributeRoutesController::class);

        $kernel = $this->app->make(HttpKernel::class);

        self::assertSame('attribute:get', $kernel->handle(Request::create('/attribute-routes/users'))->content());
        self::assertSame('attribute:post', $kernel->handle(Request::create('/attribute-routes/users', 'POST'))->content());
        self::assertSame('attribute:put:42', $kernel->handle(Request::create('/attribute-routes/users/42', 'PUT'))->content());
        self::assertSame('attribute:patch:42', $kernel->handle(Request::create('/attribute-routes/users/42', 'PATCH'))->content());
        self::assertSame('attribute:delete:42', $kernel->handle(Request::create('/attribute-routes/users/42', 'DELETE'))->content());
    }

    public function test_it_registers_name_domain_and_middleware_from_controller_attributes(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('app.url', 'https://framework.test');

        $router = $this->app->make(Router::class);
        $router->attributeRoutes(TestHttpAttributeMetadataController::class);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create(
            '/attribute-meta/users/42',
            'GET',
            [],
            [],
            [],
            [],
            [],
            ['HTTP_HOST' => 'tenant.example.com'],
        ));

        self::assertSame(200, $response->statusCode());
        self::assertSame('attribute:meta:42', $response->content());
        self::assertSame('passed', $response->headers()['X-Middleware']);
        self::assertSame('passed', $response->headers()['X-Secondary-Middleware']);
        self::assertSame('https://tenant.example.com/attribute-meta/users/42', route('attribute.meta.show', [
            'id' => 42,
        ], true));
    }

    public function test_it_dispatches_action_routes(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/actions/{id}', TestShowUserAction::class);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/actions/42'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('action:42:/actions/42', $response->content());
    }

    public function test_it_runs_middlewares_around_the_route_dispatcher(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/middleware', fn() => new Response('ok'));

        $kernel = $this->app->make(HttpKernel::class);
        $kernel->setMiddlewares([TestHeaderMiddleware::class]);

        $response = $kernel->handle(Request::create('/middleware'));

        self::assertSame('passed', $response->headers()['X-Middleware']);
    }

    public function test_it_runs_route_middlewares_around_the_endpoint_dispatcher(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/route-middleware', fn() => new Response('ok'))
            ->middleware(TestHeaderMiddleware::class);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/route-middleware'));

        self::assertSame('passed', $response->headers()['X-Middleware']);
    }

    public function test_it_executes_global_and_route_middlewares_in_stable_order(): void
    {
        TestExecutionTrace::reset();

        $router = $this->app->make(Router::class);
        $router->get('/pipeline-order', function (): string {
            TestExecutionTrace::push('action');

            return 'ok';
        })->middleware([
            TestRouteTraceMiddleware::class,
            static function (Request $request, \Closure $next): mixed {
                TestExecutionTrace::push('route-closure-before');
                $response = $next($request);
                TestExecutionTrace::push('route-closure-after');

                return $response;
            },
        ]);

        $kernel = $this->app->make(HttpKernel::class);
        $kernel->setMiddlewares([TestGlobalTraceMiddleware::class]);
        $kernel->handle(Request::create('/pipeline-order'));

        self::assertSame([
            'global-before',
            'route-before',
            'route-closure-before',
            'action',
            'route-closure-after',
            'route-after',
            'global-after',
        ], TestExecutionTrace::all());
    }

    public function test_it_exposes_route_context_to_route_middlewares(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/context-http', fn() => new Response('ok'))
            ->middleware(TestContextHeaderMiddleware::class);
        $router->get('/context-spa', fn() => new Response('ok'))
            ->spa()
            ->middleware(TestContextHeaderMiddleware::class);
        $router->get('/context-api', fn() => new Response('ok'))
            ->api()
            ->middleware(TestContextHeaderMiddleware::class);

        $httpResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/context-http'));
        $spaResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/context-spa'));
        $apiResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/context-api'));

        self::assertSame('http', $httpResponse->headers()['X-Route-Context']);
        self::assertSame('spa', $spaResponse->headers()['X-Route-Context']);
        self::assertSame('api', $apiResponse->headers()['X-Route-Context']);
    }

    public function test_it_applies_group_middlewares_to_registered_routes(): void
    {
        $router = $this->app->make(Router::class);
        $router->group(['middleware' => TestHeaderMiddleware::class], function (Router $router): void {
            $router->get('/group-middleware', fn() => new Response('ok'));
        });

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/group-middleware'));

        self::assertSame('passed', $response->headers()['X-Middleware']);
    }

    public function test_it_composes_nested_group_middlewares_prefixes_and_route_middlewares_in_stable_order(): void
    {
        TestExecutionTrace::reset();

        $router = $this->app->make(Router::class);
        $router->group([
            'prefix' => '/api',
            'middleware' => TestOuterGroupTraceMiddleware::class,
        ], function (Router $router): void {
            $router->group([
                'prefix' => '/v1',
                'middleware' => TestInnerGroupTraceMiddleware::class,
            ], function (Router $router): void {
                $router->get('/users', function (): string {
                    TestExecutionTrace::push('action');

                    return 'ok';
                })->middleware(TestRouteTraceMiddleware::class);
            });
        });

        $kernel = $this->app->make(HttpKernel::class);
        $kernel->setMiddlewares([TestGlobalTraceMiddleware::class]);
        $response = $kernel->handle(Request::create('/api/v1/users'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('ok', $response->content());
        self::assertSame([
            'global-before',
            'group-outer-before',
            'group-inner-before',
            'route-before',
            'action',
            'route-after',
            'group-inner-after',
            'group-outer-after',
            'global-after',
        ], TestExecutionTrace::all());
    }

    public function test_it_can_apply_group_domains_to_routes(): void
    {
        $router = $this->app->make(Router::class);
        $router->group(['domain' => 'admin.example.com'], function (Router $router): void {
            $router->get('/group-domain', fn() => 'admin');
        });

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/group-domain', 'GET', [], [], [], [], [], [
            'HTTP_HOST' => 'admin.example.com',
        ]));

        self::assertSame(200, $response->statusCode());
        self::assertSame('admin', $response->content());
    }

    public function test_it_supports_fluent_group_builders_for_prefix_name_and_domain(): void
    {
        $router = $this->app->make(Router::class);
        $router->prefix('/admin')
            ->name('admin')
            ->domain('admin.example.com')
            ->group(function (Router $router): void {
                $router->get('/users', fn() => 'users')->name('users.index');
            });

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/admin/users', 'GET', [], [], [], [], [], [
            'HTTP_HOST' => 'admin.example.com',
        ]));

        self::assertSame(200, $response->statusCode());
        self::assertSame('users', $response->content());
        self::assertSame('//admin.example.com/admin/users', $router->route('admin.users.index'));
    }

    public function test_it_registers_conventional_resource_routes(): void
    {
        $router = $this->app->make(Router::class);
        $routes = $router->resource('posts', TestResourceController::class);

        self::assertCount(7, $routes);
        self::assertSame('/posts', $router->collection()->named('posts.index')?->uri());
        self::assertSame('/posts/{post}', $router->collection()->named('posts.show')?->uri());
        self::assertSame('/posts/{post}/edit', $router->collection()->named('posts.edit')?->uri());
        self::assertSame('/posts/42', $router->route('posts.show', ['post' => 42]));

        $indexResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts'));
        $storeResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts', 'POST'));
        $showResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/42'));
        $updateResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/42', 'PATCH'));
        $deleteResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/42', 'DELETE'));

        self::assertSame('index', $indexResponse->content());
        self::assertSame('store', $storeResponse->content());
        self::assertSame('show:42', $showResponse->content());
        self::assertSame('update:42', $updateResponse->content());
        self::assertSame('destroy:42', $deleteResponse->content());
    }

    public function test_resource_routes_inherit_fluent_group_prefixes_names_and_domains(): void
    {
        $router = $this->app->make(Router::class);
        $router->prefix('/admin')
            ->name('admin')
            ->domain('admin.example.com')
            ->group(function (Router $router): void {
                $router->resource('posts', TestResourceController::class);
            });

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/admin/posts/7', 'GET', [], [], [], [], [], [
            'HTTP_HOST' => 'admin.example.com',
        ]));

        self::assertSame(200, $response->statusCode());
        self::assertSame('show:7', $response->content());
        self::assertSame('//admin.example.com/admin/posts/7', $router->route('admin.posts.show', ['post' => 7]));
    }

    public function test_it_can_limit_resource_routes_with_only(): void
    {
        $router = $this->app->make(Router::class);
        $routes = $router->resource('posts', TestResourceController::class)
            ->only(['index', 'show']);

        self::assertCount(2, $routes);
        self::assertNotNull($router->collection()->named('posts.index'));
        self::assertNotNull($router->collection()->named('posts.show'));
        self::assertNull($router->collection()->named('posts.create'));
        self::assertNull($router->collection()->named('posts.store'));

        $indexResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts'));
        $showResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/9'));
        $storeResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts', 'POST'));

        self::assertSame(200, $indexResponse->statusCode());
        self::assertSame('index', $indexResponse->content());
        self::assertSame(200, $showResponse->statusCode());
        self::assertSame('show:9', $showResponse->content());
        self::assertSame(405, $storeResponse->statusCode());
    }

    public function test_it_can_exclude_resource_routes_with_except(): void
    {
        $router = $this->app->make(Router::class);
        $routes = $router->resource('posts', TestResourceController::class)
            ->except(['destroy', 'edit']);

        self::assertCount(5, $routes);
        self::assertNull($router->collection()->named('posts.destroy'));
        self::assertNull($router->collection()->named('posts.edit'));
        self::assertNotNull($router->collection()->named('posts.update'));

        $editResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/4/edit'));
        $deleteResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/4', 'DELETE'));
        $updateResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/4', 'PATCH'));

        self::assertSame(404, $editResponse->statusCode());
        self::assertSame(405, $deleteResponse->statusCode());
        self::assertSame(200, $updateResponse->statusCode());
        self::assertSame('update:4', $updateResponse->content());
    }

    public function test_it_can_register_api_resource_routes_without_create_and_edit(): void
    {
        $router = $this->app->make(Router::class);
        $routes = $router->apiResource('posts', TestResourceController::class);

        self::assertCount(5, $routes);
        self::assertNull($router->collection()->named('posts.create'));
        self::assertNull($router->collection()->named('posts.edit'));
        self::assertNotNull($router->collection()->named('posts.index'));
        self::assertNotNull($router->collection()->named('posts.destroy'));

        $createResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/create'));
        $editResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/4/edit'));
        $showResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/4'));

        self::assertSame(404, $createResponse->statusCode());
        self::assertSame(404, $editResponse->statusCode());
        self::assertSame(200, $showResponse->statusCode());
        self::assertSame('show:4', $showResponse->content());
    }

    public function test_it_rejects_invalid_resource_actions_in_only_and_except(): void
    {
        $router = $this->app->make(Router::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Resource action [publish] is not supported.');

        $router->resource('posts', TestResourceController::class)->only(['publish']);
    }

    public function test_it_can_customize_resource_route_names(): void
    {
        $router = $this->app->make(Router::class);
        $routes = $router->resource('posts', TestResourceController::class)->names([
            'index' => 'content.posts.list',
            'show' => 'content.posts.view',
        ]);

        self::assertCount(7, $routes);
        self::assertNull($router->collection()->named('posts.index'));
        self::assertNull($router->collection()->named('posts.show'));
        self::assertNotNull($router->collection()->named('content.posts.list'));
        self::assertNotNull($router->collection()->named('content.posts.view'));
        self::assertSame('/posts/5', $router->route('content.posts.view', ['post' => 5]));
    }

    public function test_it_can_customize_resource_parameter_names(): void
    {
        $router = $this->app->make(Router::class);
        $routes = $router->resource('posts', TestResourceController::class)
            ->parameter('entry');

        self::assertCount(7, $routes);
        self::assertSame('/posts/{entry}', $router->collection()->named('posts.show')?->uri());
        self::assertSame('/posts/8', $router->route('posts.show', ['entry' => 8]));

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/8'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('show:8', $response->content());
    }

    public function test_it_can_customize_resource_parameters_by_resource_key(): void
    {
        $router = $this->app->make(Router::class);
        $routes = $router->apiResource('posts', TestResourceController::class)
            ->parameters(['posts' => 'entry']);

        self::assertCount(5, $routes);
        self::assertSame('/posts/{entry}', $router->collection()->named('posts.show')?->uri());
        self::assertSame('/posts/12', $router->route('posts.show', ['entry' => 12]));

        $createResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/create'));
        $showResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/12'));

        self::assertSame(404, $createResponse->statusCode());
        self::assertSame(200, $showResponse->statusCode());
        self::assertSame('show:12', $showResponse->content());
    }

    public function test_it_registers_nested_resource_routes(): void
    {
        $router = $this->app->make(Router::class);
        $routes = $router->resource('posts.comments', TestNestedCommentController::class);

        self::assertCount(7, $routes);
        self::assertSame('/posts/{post}/comments', $router->collection()->named('posts.comments.index')?->uri());
        self::assertSame('/posts/{post}/comments/{comment}', $router->collection()->named('posts.comments.show')?->uri());
        self::assertSame('/posts/7/comments/11', $router->route('posts.comments.show', [
            'post' => 7,
            'comment' => 11,
        ]));

        $indexResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/7/comments'));
        $showResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/7/comments/11'));

        self::assertSame(200, $indexResponse->statusCode());
        self::assertSame('comments.index:7', $indexResponse->content());
        self::assertSame(200, $showResponse->statusCode());
        self::assertSame('comments.show:7:11', $showResponse->content());
    }

    public function test_it_can_register_shallow_nested_resource_routes(): void
    {
        $router = $this->app->make(Router::class);
        $routes = $router->resource('posts.comments', TestShallowCommentController::class)
            ->shallow();

        self::assertCount(7, $routes);
        self::assertSame('/posts/{post}/comments', $router->collection()->named('posts.comments.index')?->uri());
        self::assertSame('/comments/{comment}', $router->collection()->named('posts.comments.show')?->uri());
        self::assertSame('/comments/{comment}/edit', $router->collection()->named('posts.comments.edit')?->uri());
        self::assertSame('/posts/3/comments', $router->route('posts.comments.index', ['post' => 3]));
        self::assertSame('/comments/11', $router->route('posts.comments.show', ['comment' => 11]));

        $indexResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/3/comments'));
        $showResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/comments/11'));

        self::assertSame(200, $indexResponse->statusCode());
        self::assertSame('shallow.index:3', $indexResponse->content());
        self::assertSame(200, $showResponse->statusCode());
        self::assertSame('shallow.show:11', $showResponse->content());
    }

    public function test_it_can_customize_nested_resource_parent_and_child_parameters(): void
    {
        $router = $this->app->make(Router::class);
        $routes = $router->resource('posts.comments', TestNestedCommentController::class)
            ->parameters([
                'posts' => 'entry',
                'comments' => 'note',
            ]);

        self::assertCount(7, $routes);
        self::assertSame('/posts/{entry}/comments/{note}', $router->collection()->named('posts.comments.show')?->uri());
        self::assertSame('/posts/5/comments/9', $router->route('posts.comments.show', [
            'entry' => 5,
            'note' => 9,
        ]));

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/5/comments/9'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('comments.show:5:9', $response->content());
    }

    public function test_it_can_customize_resource_paths_by_action(): void
    {
        $router = $this->app->make(Router::class);
        $routes = $router->resource('posts', TestResourceController::class)
            ->paths([
                'index' => '/catalog/posts',
                'create' => '/catalog/posts/compose',
                'edit' => '/catalog/posts/{post}/revise',
            ]);

        self::assertCount(7, $routes);
        self::assertSame('/catalog/posts', $router->collection()->named('posts.index')?->uri());
        self::assertSame('/catalog/posts/compose', $router->collection()->named('posts.create')?->uri());
        self::assertSame('/catalog/posts/{post}/revise', $router->collection()->named('posts.edit')?->uri());
        self::assertSame('/catalog/posts', $router->route('posts.index'));
        self::assertSame('/catalog/posts/8/revise', $router->route('posts.edit', ['post' => 8]));

        $indexResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/catalog/posts'));
        $createResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/catalog/posts/compose'));
        $editResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/catalog/posts/8/revise'));

        self::assertSame(200, $indexResponse->statusCode());
        self::assertSame('index', $indexResponse->content());
        self::assertSame(200, $createResponse->statusCode());
        self::assertSame('create', $createResponse->content());
        self::assertSame(200, $editResponse->statusCode());
        self::assertSame('edit:8', $editResponse->content());
    }

    public function test_it_can_customize_resource_verbs_by_action(): void
    {
        $router = $this->app->make(Router::class);
        $routes = $router->resource('posts', TestResourceController::class)
            ->verbs([
                'store' => 'PUT',
                'update' => 'POST',
            ]);

        self::assertCount(7, $routes);
        self::assertSame(['PUT'], $router->collection()->named('posts.store')?->methods());
        self::assertSame(['POST'], $router->collection()->named('posts.update')?->methods());

        $storeResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts', 'PUT'));
        $updateResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/8', 'POST'));
        $oldStoreResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts', 'POST'));
        $oldUpdateResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/8', 'PATCH'));

        self::assertSame(200, $storeResponse->statusCode());
        self::assertSame('store', $storeResponse->content());
        self::assertSame(200, $updateResponse->statusCode());
        self::assertSame('update:8', $updateResponse->content());
        self::assertSame(405, $oldStoreResponse->statusCode());
        self::assertSame(405, $oldUpdateResponse->statusCode());
    }

    public function test_it_can_resolve_typed_resource_bindings_for_member_routes(): void
    {
        TestBindableCommentResource::seed(['7' => 'comment-7']);

        $router = $this->app->make(Router::class);
        $router->resource('posts.comments', TestBindableCommentController::class)
            ->only(['show']);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/4/comments/7'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('bound:4:comment-7', $response->content());
    }

    public function test_it_can_redirect_when_a_bound_resource_is_missing(): void
    {
        TestBindableCommentResource::seed([]);

        $router = $this->app->make(Router::class);
        $router->get('/missing-comments', fn() => 'missing-comments')->name('comments.missing');
        $router->resource('posts.comments', TestBindableCommentController::class)
            ->only(['show'])
            ->missing('comments.missing');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/4/comments/99'));

        self::assertSame(302, $response->statusCode());
        self::assertSame('/missing-comments?post=4&comment=99', $response->headers()['Location']);
    }

    public function test_it_can_return_a_custom_status_when_a_bound_resource_is_missing(): void
    {
        TestBindableCommentResource::seed([]);

        $router = $this->app->make(Router::class);
        $router->resource('posts.comments', TestBindableCommentController::class)
            ->only(['show'])
            ->missing(410);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/posts/4/comments/99'));

        self::assertSame(410, $response->statusCode());
        self::assertSame('', $response->content());
    }

    public function test_it_resolves_global_middleware_aliases_before_runtime(): void
    {
        $kernel = $this->app->make(HttpKernel::class);
        $kernel->aliasMiddleware('header', TestHeaderMiddleware::class);

        $router = $this->app->make(Router::class);
        $router->get('/global-alias', fn() => new Response('ok'));

        $kernel->setMiddlewares(['header']);
        $response = $kernel->handle(Request::create('/global-alias'));

        self::assertSame('passed', $response->headers()['X-Middleware']);
    }

    public function test_it_compiles_the_global_pipeline_when_middlewares_are_registered(): void
    {
        $kernel = $this->app->make(HttpKernel::class);
        $kernel->aliasMiddleware('header', TestHeaderMiddleware::class);
        $kernel->setMiddlewares(['header', TestHeaderMiddleware::class]);

        self::assertSame([TestHeaderMiddleware::class], $kernel->compiledMiddlewarePipeline()->middlewares());
        self::assertNotSame('', $kernel->compiledMiddlewarePipeline()->id());
    }

    public function test_it_resolves_group_middleware_aliases_before_runtime(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('header', TestHeaderMiddleware::class);
        $router->group(['middleware' => 'header'], function (Router $router): void {
            $router->get('/group-alias', fn() => new Response('ok'));
        });

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/group-alias'));

        self::assertSame('passed', $response->headers()['X-Middleware']);
    }

    public function test_it_resolves_route_middleware_aliases_before_runtime(): void
    {
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('header', TestHeaderMiddleware::class);
        $router->get('/route-alias', fn() => new Response('ok'))->middleware('header');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/route-alias'));

        self::assertSame('passed', $response->headers()['X-Middleware']);
    }

    public function test_it_can_dispatch_routes_using_loaded_pipeline_artifacts(): void
    {
        $this->app->make(ConfigRepository::class)->set('app.env', 'production');

        $router = $this->app->make(Router::class);
        $route = $router->get('/artifact-pipeline', fn() => new Response('ok'))->middleware(TestHeaderMiddleware::class);

        $this->app->make(PipelineArtifactStore::class)->compileAndWrite($router);
        $router->reloadPipelineArtifacts();

        self::assertSame($route->routePipeline(), $router->resolvedRoutePipeline($route));

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/artifact-pipeline'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('passed', $response->headers()['X-Middleware']);
    }

    public function test_it_can_dispatch_routes_using_a_loaded_collection_artifact(): void
    {
        $this->app->make(ConfigRepository::class)->set('app.env', 'production');

        $router = $this->app->make(Router::class);
        $route = $router->get('/artifact-collection', TestStringController::class . '@show')->name('artifact.collection');

        $this->app->make(CollectionArtifactStore::class)->compileAndWrite($router);
        $router->reloadCollectionArtifacts();

        $compiledRoute = $router->compiledCollection()->named('artifact.collection');

        self::assertNotNull($compiledRoute);
        self::assertNotSame($route, $compiledRoute);
        self::assertSame(TestStringController::class . '@show', $compiledRoute->action());

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/artifact-collection'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('controller-string', $response->content());
    }

    public function test_it_dispatches_collection_artifact_routes_without_reading_php_attributes_in_runtime(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/artifact-attributes/{id}', TestAttributedController::class . '@show')->name('artifact.attributes');

        $this->app->make(CollectionArtifactStore::class)->compileAndWrite($router);
        $router->reloadCollectionArtifacts();

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/artifact-attributes/99'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('attributes:99', $response->content());
    }

    public function test_it_dispatches_attribute_registered_routes_from_collection_artifacts(): void
    {
        $router = $this->app->make(Router::class);
        $router->attributeRoutes(TestHttpAttributeRoutesController::class);

        $this->app->make(CollectionArtifactStore::class)->compileAndWrite($router);
        $router->reloadCollectionArtifacts();

        $kernel = $this->app->make(HttpKernel::class);

        self::assertSame('attribute:get', $kernel->handle(Request::create('/attribute-routes/users'))->content());
        self::assertSame('attribute:delete:77', $kernel->handle(Request::create('/attribute-routes/users/77', 'DELETE'))->content());
    }

    public function test_it_dispatches_attribute_metadata_routes_from_collection_and_pipeline_artifacts(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('app.url', 'https://framework.test');

        $router = $this->app->make(Router::class);
        $router->attributeRoutes(TestHttpAttributeMetadataController::class);

        $this->app->make(CollectionArtifactStore::class)->compileAndWrite($router);
        $this->app->make(PipelineArtifactStore::class)->compileAndWrite($router);
        $router->reloadCollectionArtifacts();
        $router->reloadPipelineArtifacts();

        $response = $this->app->make(HttpKernel::class)->handle(Request::create(
            '/attribute-meta/users/77',
            'GET',
            [],
            [],
            [],
            [],
            [],
            ['HTTP_HOST' => 'tenant.example.com'],
        ));

        self::assertSame(200, $response->statusCode());
        self::assertSame('attribute:meta:77', $response->content());
        self::assertSame('passed', $response->headers()['X-Middleware']);
        self::assertSame('passed', $response->headers()['X-Secondary-Middleware']);
        self::assertSame('https://tenant.example.com/attribute-meta/users/77', route('attribute.meta.show', [
            'id' => 77,
        ], true));
    }

    public function test_it_can_match_requests_using_a_loaded_tree_artifact(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/artifact-tree/head', TestStringController::class . '@show')->name('artifact.tree.head');
        $router->post('/artifact-tree/submit/{id}', TestStringController::class . '@show')
            ->name('artifact.tree.submit')
            ->whereNumber('id');

        $this->app->make(CollectionArtifactStore::class)->compileAndWrite($router);
        $this->app->make(TreeArtifactStore::class)->compileAndWrite($router);
        $router->reloadCollectionArtifacts();

        $headResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/artifact-tree/head', 'HEAD'));

        self::assertSame(200, $headResponse->statusCode());
        self::assertSame('', $headResponse->content());

        $methodResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/artifact-tree/submit/42', 'GET'));

        self::assertSame(405, $methodResponse->statusCode());
        self::assertSame('POST, OPTIONS', $methodResponse->headers()['Allow']);

        $optionsResponse = $this->app->make(HttpKernel::class)->handle(Request::create('/artifact-tree/submit/42', 'OPTIONS'));

        self::assertSame(204, $optionsResponse->statusCode());
        self::assertSame('POST, OPTIONS', $optionsResponse->headers()['Allow']);
    }

    public function test_it_can_apply_loaded_metadata_artifacts_over_collection_metadata(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/artifact-metadata', TestMetadataEchoController::class . '@show')
            ->name('artifact.metadata')
            ->meta([
                'auth' => 'session',
                'runtime' => ['mode' => 'spa'],
                'csrf' => true,
                'guest' => true,
            ]);

        $this->app->make(CollectionArtifactStore::class)->compileAndWrite($router);
        $this->app->make(MetadataArtifactStore::class)->compileAndWrite($router);
        $this->clearCollectionArtifactMetadata('artifact.metadata');

        $router->reloadCollectionArtifacts();

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/artifact-metadata'));
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode());
        self::assertSame('session', $payload['auth']);
        self::assertSame(['mode' => 'spa'], $payload['runtime']);
        self::assertTrue($payload['csrf']);
        self::assertTrue($payload['guest']);
        self::assertSame('artifact.metadata', $payload['all']['name']);
        self::assertSame(['GET'], $payload['all']['methods']);
    }

    public function test_it_falls_back_to_live_routes_when_the_version_artifact_detects_a_checksum_mismatch(): void
    {
        $router = $this->app->make(Router::class);
        $route = $router->get('/artifact-version', TestStringController::class . '@show')->name('artifact.version');

        $this->app->make(CollectionArtifactStore::class)->compileAndWrite($router);
        $this->app->make(TreeArtifactStore::class)->compileAndWrite($router);
        $this->app->make(MetadataArtifactStore::class)->compileAndWrite($router);
        $this->app->make(PipelineArtifactStore::class)->compileAndWrite($router);
        $this->app->make(VersionArtifactStore::class)->compileAndWrite($router);

        $collectionPath = $this->app->cachePath('routes/collection.php');
        $collectionContents = file_get_contents($collectionPath);

        if (! is_string($collectionContents)) {
            self::fail('Collection artifact contents could not be read.');
        }

        file_put_contents($collectionPath, $collectionContents . "\n");
        $router->reloadCollectionArtifacts();

        $compiledRoute = $router->compiledCollection()->named('artifact.version');

        self::assertSame($route, $compiledRoute);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/artifact-version'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('controller-string', $response->content());
    }

    public function test_it_invalidates_routing_artifacts_automatically_in_development(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('app.env', 'local');

        $router = $this->app->make(Router::class);
        $route = $router->get('/artifact-dev', TestResponseController::class . '@show')->name('artifact.dev')->middleware(TestHeaderMiddleware::class);

        $this->app->make(CollectionArtifactStore::class)->compileAndWrite($router);
        $this->app->make(TreeArtifactStore::class)->compileAndWrite($router);
        $this->app->make(MetadataArtifactStore::class)->compileAndWrite($router);
        $this->app->make(PipelineArtifactStore::class)->compileAndWrite($router);
        $this->app->make(VersionArtifactStore::class)->compileAndWrite($router);

        foreach ($this->routingArtifactPaths() as $path) {
            self::assertFileExists($path);
        }

        $router->reloadCollectionArtifacts();
        $router->reloadPipelineArtifacts();

        $compiledRoute = $router->compiledCollection()->named('artifact.dev');

        self::assertSame($route, $compiledRoute);
        self::assertSame($route->routePipeline(), $router->resolvedRoutePipeline($route));

        foreach ($this->routingArtifactPaths() as $path) {
            self::assertFileDoesNotExist($path);
        }

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/artifact-dev'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('passed', $response->headers()['X-Middleware']);
    }

    public function test_it_uses_routing_artifacts_automatically_in_production_without_manual_reload(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('app.env', 'production');

        $router = $this->app->make(Router::class);
        $route = $router->get('/artifact-production', TestResponseController::class . '@show')
            ->name('artifact.production')
            ->middleware(TestHeaderMiddleware::class)
            ->meta('auth', 'session');

        $this->app->make(CollectionArtifactStore::class)->compileAndWrite($router);
        $this->app->make(TreeArtifactStore::class)->compileAndWrite($router);
        $this->app->make(MetadataArtifactStore::class)->compileAndWrite($router);
        $this->app->make(PipelineArtifactStore::class)->compileAndWrite($router);
        $this->app->make(VersionArtifactStore::class)->compileAndWrite($router);

        $compiledCollection = $router->compiledCollection();
        $compiledRoute = $compiledCollection->named('artifact.production');

        self::assertNotNull($compiledRoute);
        self::assertNotSame($route, $compiledRoute);
        self::assertSame($compiledCollection, $router->compiledCollection());
        self::assertSame('session', $compiledRoute->routeMetadata()->get('auth'));

        $resolvedPipeline = $router->resolvedRoutePipeline($compiledRoute);

        self::assertSame($compiledRoute->routePipeline(), $resolvedPipeline);
        self::assertSame($compiledRoute->routePipeline()->id(), $resolvedPipeline->id());

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/artifact-production'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('ok', $response->content());
        self::assertSame('passed', $response->headers()['X-Middleware']);
    }

    public function test_it_generates_relative_named_route_urls_with_query_strings_and_fragments(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/users/{user}/posts/{post}', fn() => 'ok')->name('users.posts.show');

        $url = route('users.posts.show', [
            'user' => 42,
            'post' => 100,
            'filter' => 'recent',
            '_query' => ['page' => 2],
            '_fragment' => 'comments',
        ]);

        self::assertSame('/users/42/posts/100?filter=recent&page=2#comments', $url);
    }

    public function test_it_generates_domain_aware_relative_and_absolute_named_route_urls(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('app.url', 'https://framework.test');

        $router = $this->app->make(Router::class);
        $router->get('/dashboard/{page}', fn() => 'ok')
            ->name('tenant.dashboard')
            ->domain('{tenant}.example.com');

        self::assertSame('//acme.example.com/dashboard/home', route('tenant.dashboard', [
            'tenant' => 'acme',
            'page' => 'home',
        ]));

        self::assertSame('https://acme.example.com/dashboard/home', route('tenant.dashboard', [
            'tenant' => 'acme',
            'page' => 'home',
        ], true));
    }

    public function test_it_generates_named_route_urls_from_loaded_collection_artifacts(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/artifact-url/{id}', TestStringController::class . '@show')->name('artifact.url');

        $this->app->make(CollectionArtifactStore::class)->compileAndWrite($router);
        $router->reloadCollectionArtifacts();

        self::assertSame('/artifact-url/42', $router->route('artifact.url', ['id' => 42]));
    }

    public function test_it_generates_signed_route_urls(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('app.url', 'https://framework.test');
        $config->set('app.key', 'base64:test-secret');

        $router = $this->app->make(Router::class);
        $router->get('/unsubscribe/{user}', fn() => 'ok')->name('unsubscribe');

        $url = $router->signedRoute('unsubscribe', [
            'user' => 42,
            'channel' => 'email',
            '_fragment' => 'confirm',
        ]);

        self::assertStringStartsWith('https://framework.test/unsubscribe/42?', $url);
        self::assertStringContainsString('channel=email', $url);
        self::assertStringContainsString('signature=', $url);
        self::assertStringEndsWith('#confirm', $url);
    }

    public function test_it_validates_signed_route_requests(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('app.url', 'https://framework.test');
        $config->set('app.key', 'base64:test-secret');

        $router = $this->app->make(Router::class);
        $router->get('/unsubscribe/{user}', fn() => 'ok')->name('unsubscribe');

        $signedUrl = $router->signedRoute('unsubscribe', [
            'user' => 42,
            'channel' => 'email',
        ]);

        self::assertTrue($router->hasValidSignature(Request::create($signedUrl)));
        self::assertFalse($router->hasValidSignature(Request::create(str_replace('channel=email', 'channel=sms', $signedUrl))));
        self::assertFalse($router->hasValidSignature(Request::create('https://framework.test/unsubscribe/42?channel=email')));
    }

    public function test_it_exposes_a_signed_route_helper(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('app.url', 'https://framework.test');
        $config->set('app.key', 'base64:test-secret');

        $router = $this->app->make(Router::class);
        $router->get('/invitations/{invitation}', fn() => 'ok')->name('invitations.accept');

        $url = signed_route('invitations.accept', [
            'invitation' => 'abc123',
            'via' => 'mail',
        ]);

        self::assertStringStartsWith('https://framework.test/invitations/abc123?', $url);
        self::assertStringContainsString('via=mail', $url);
        self::assertStringContainsString('signature=', $url);
    }

    public function test_it_generates_temporary_signed_route_urls(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('app.url', 'https://framework.test');
        $config->set('app.key', 'base64:test-secret');

        $router = $this->app->make(Router::class);
        $router->get('/magic-login/{user}', fn() => 'ok')->name('magic.login');

        $url = $router->temporarySignedRoute(
            'magic.login',
            new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
            [
                'user' => 42,
                'via' => 'mail',
                '_fragment' => 'confirm',
            ],
        );

        self::assertStringStartsWith('https://framework.test/magic-login/42?', $url);
        self::assertStringContainsString('via=mail', $url);
        self::assertStringContainsString('expires=1893456000', $url);
        self::assertStringContainsString('signature=', $url);
        self::assertStringEndsWith('#confirm', $url);
    }

    public function test_it_validates_temporary_signed_route_requests_and_rejects_expired_urls(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('app.url', 'https://framework.test');
        $config->set('app.key', 'base64:test-secret');

        $router = $this->app->make(Router::class);
        $router->get('/magic-login/{user}', fn() => 'ok')->name('magic.login');

        $validUrl = $router->temporarySignedRoute(
            'magic.login',
            new DateTimeImmutable('+1 day'),
            [
                'user' => 42,
                'via' => 'mail',
            ],
        );

        $expiredUrl = $router->temporarySignedRoute(
            'magic.login',
            new DateTimeImmutable('-1 day'),
            [
                'user' => 42,
                'via' => 'mail',
            ],
        );

        self::assertTrue($router->hasValidSignature(Request::create($validUrl)));
        self::assertFalse($router->hasValidSignature(Request::create($expiredUrl)));
        self::assertFalse($router->hasValidSignature(Request::create(str_replace('expires=', 'expires=abc', $validUrl))));
    }

    public function test_it_accepts_cache_style_expiration_inputs_for_temporary_signed_routes(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('app.url', 'https://framework.test');
        $config->set('app.key', 'base64:test-secret');

        $router = $this->app->make(Router::class);
        $router->get('/invites/{code}', fn() => 'ok')->name('invites.accept');

        $intervalUrl = $router->temporarySignedRoute(
            'invites.accept',
            new DateInterval('PT1H'),
            ['code' => 'abc123'],
        );
        $ttlUrl = $router->temporarySignedRoute(
            'invites.accept',
            3600,
            ['code' => 'xyz789'],
        );

        self::assertStringContainsString('expires=', $intervalUrl);
        self::assertStringContainsString('signature=', $intervalUrl);
        self::assertStringContainsString('expires=', $ttlUrl);
        self::assertStringContainsString('signature=', $ttlUrl);
    }

    public function test_it_exposes_a_temporary_signed_route_helper(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('app.url', 'https://framework.test');
        $config->set('app.key', 'base64:test-secret');

        $router = $this->app->make(Router::class);
        $router->get('/downloads/{file}', fn() => 'ok')->name('downloads.secure');

        $url = temporary_signed_route(
            'downloads.secure',
            new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
            [
                'file' => 'report.pdf',
                'disposition' => 'attachment',
            ],
        );

        self::assertStringStartsWith('https://framework.test/downloads/report.pdf?', $url);
        self::assertStringContainsString('disposition=attachment', $url);
        self::assertStringContainsString('expires=1893456000', $url);
        self::assertStringContainsString('signature=', $url);
    }

    public function test_it_allows_signed_route_requests_through_the_signed_middleware(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('app.url', 'https://framework.test');
        $config->set('app.key', 'base64:test-secret');

        $router = $this->app->make(Router::class);
        $router->get('/secure-download/{file}', fn(string $file) => 'download:' . $file)
            ->name('secure.download')
            ->middleware('signed');

        $signedUrl = $router->signedRoute('secure.download', [
            'file' => 'report.pdf',
        ]);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create($signedUrl));

        self::assertSame(200, $response->statusCode());
        self::assertSame('download:report.pdf', $response->content());
    }

    public function test_it_rejects_invalid_signed_route_requests_through_the_signed_middleware(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('app.url', 'https://framework.test');
        $config->set('app.key', 'base64:test-secret');

        $router = $this->app->make(Router::class);
        $router->get('/secure-download/{file}', fn(string $file) => 'download:' . $file)
            ->name('secure.download')
            ->middleware('signed');

        $signedUrl = $router->signedRoute('secure.download', [
            'file' => 'report.pdf',
            'via' => 'mail',
        ]);
        $tamperedUrl = str_replace('via=mail', 'via=sms', $signedUrl);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create($tamperedUrl));

        self::assertSame(403, $response->statusCode());
        self::assertSame('text/html; charset=UTF-8', $response->headers()['Content-Type']);
        self::assertStringContainsString('Forbidden', $response->content());
        self::assertStringContainsString('Invalid signature.', $response->content());
    }

    public function test_it_rejects_expired_temporary_signed_route_requests_through_the_signed_middleware(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('app.url', 'https://framework.test');
        $config->set('app.key', 'base64:test-secret');

        $router = $this->app->make(Router::class);
        $router->get('/secure-magic-login/{user}', fn(string $user) => 'login:' . $user)
            ->name('secure.magic.login')
            ->middleware('signed');

        $expiredUrl = $router->temporarySignedRoute(
            'secure.magic.login',
            new DateTimeImmutable('-5 minutes'),
            ['user' => '42'],
        );

        $response = $this->app->make(HttpKernel::class)->handle(Request::create($expiredUrl));

        self::assertSame(403, $response->statusCode());
        self::assertStringContainsString('Invalid signature.', $response->content());
    }

    public function test_it_allows_signed_component_route_requests_through_the_signed_middleware(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('app.url', 'https://framework.test');
        $config->set('app.key', 'base64:test-secret');

        $router = $this->app->make(Router::class);
        $router->get('/secure-component', TestManifestComponentPage::class)
            ->name('secure.component')
            ->middleware('signed')
            ->componentPage();

        $signedUrl = signed_route('secure.component');
        $response = $this->app->make(HttpKernel::class)->handle(Request::create($signedUrl));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('manifest-component', $response->content());
    }

    public function test_it_emits_a_semantic_navigation_error_reason_for_invalid_signed_component_route_requests(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        $config->set('app.url', 'https://framework.test');
        $config->set('app.key', 'base64:test-secret');

        $router = $this->app->make(Router::class);
        $router->get('/secure-component', TestManifestComponentPage::class)
            ->name('secure.component')
            ->middleware('signed')
            ->componentPage();

        $signedUrl = $router->signedRoute('secure.component', ['via' => 'mail']);
        $tamperedUrl = str_replace('via=mail', 'via=sms', $signedUrl);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create(
            $tamperedUrl,
            'GET',
            [],
            [],
            [],
            [],
            [],
            [
                'HTTP_X_REQUESTED_WITH' => 'VoltStack',
                'HTTP_X_VOLT_NAVIGATE' => 'true',
            ],
        ));

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) ($response->headers()['X-Volt-Navigation'] ?? ''), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->statusCode());
        self::assertSame('security.invalid_signature', $response->headers()['X-Volt-Error-Code'] ?? null);
        self::assertSame([
            'code' => 403,
            'message' => 'Forbidden',
            'reason' => 'security.invalid_signature',
        ], $payload['error'] ?? null);
    }

    public function test_it_marks_internal_volt_routes_with_explicit_transport_metadata(): void
    {
        $router = $this->app->make(Router::class);
        $compiledRoutes = $router->compiledCollection()->all();
        $runtimeAssetRoute = null;
        $routesManifestRoute = null;
        $protocolActionRoute = null;

        foreach ($compiledRoutes as $route) {
            if ($route->uri() === '/_volt/runtime.js') {
                $runtimeAssetRoute = $route;
            }

            if ($route->uri() === '/_volt/routes-manifest.json') {
                $routesManifestRoute = $route;
            }

            if ($route->uri() === '/_volt/action') {
                $protocolActionRoute = $route;
            }
        }

        self::assertNotNull($runtimeAssetRoute);
        self::assertNotNull($routesManifestRoute);
        self::assertNotNull($protocolActionRoute);
        self::assertSame('internal', $runtimeAssetRoute->routeMetadata()->get('transport'));
        self::assertSame('volt.runtime.asset', $runtimeAssetRoute->routeMetadata()->get('endpoint'));
        self::assertSame('volt', $runtimeAssetRoute->routeMetadata()->get('protocol'));
        self::assertSame('internal', $routesManifestRoute->routeMetadata()->get('transport'));
        self::assertSame('volt.routes.manifest', $routesManifestRoute->routeMetadata()->get('endpoint'));
        self::assertSame('volt', $routesManifestRoute->routeMetadata()->get('protocol'));
        self::assertSame('internal', $protocolActionRoute->routeMetadata()->get('transport'));
        self::assertSame('volt.protocol.action', $protocolActionRoute->routeMetadata()->get('endpoint'));
        self::assertSame('volt', $protocolActionRoute->routeMetadata()->get('protocol'));
    }

    public function test_it_exposes_the_current_runtime_http_verb_contract_through_internal_routes(): void
    {
        $router = $this->app->make(Router::class);
        $compiledRoutes = $router->compiledCollection()->all();
        $runtimeAssetRoute = null;
        $routesManifestRoute = null;
        $protocolActionRoute = null;

        foreach ($compiledRoutes as $route) {
            if ($route->uri() === '/_volt/runtime.js') {
                $runtimeAssetRoute = $route;
            }

            if ($route->uri() === '/_volt/routes-manifest.json') {
                $routesManifestRoute = $route;
            }

            if ($route->uri() === '/_volt/action') {
                $protocolActionRoute = $route;
            }
        }

        self::assertNotNull($runtimeAssetRoute);
        self::assertNotNull($routesManifestRoute);
        self::assertNotNull($protocolActionRoute);
        self::assertSame(['GET'], $runtimeAssetRoute->methods());
        self::assertSame(['GET'], $runtimeAssetRoute->routeMetadata()->get('methods'));
        self::assertSame(['GET'], $routesManifestRoute->methods());
        self::assertSame(['GET'], $routesManifestRoute->routeMetadata()->get('methods'));
        self::assertSame(['POST'], $protocolActionRoute->methods());
        self::assertSame(['POST'], $protocolActionRoute->routeMetadata()->get('methods'));
    }

    public function test_it_throws_a_clear_error_when_a_required_route_parameter_is_missing_during_url_generation(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/users/{id}', fn() => 'ok')->name('users.show');

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Missing required route parameter [id] for route [users.show].');

        route('users.show');
    }

    public function test_it_rejects_unknown_route_middleware_aliases_during_registration(): void
    {
        $router = $this->app->make(Router::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Middleware [missing] is not a registered alias or valid class.');

        $router->get('/missing-alias', fn() => 'ok')->middleware('missing');
    }

    public function test_it_deduplicates_global_middlewares_while_preserving_first_occurrence(): void
    {
        TestExecutionTrace::reset();

        $kernel = $this->app->make(HttpKernel::class);
        $kernel->aliasMiddleware('global-trace', TestGlobalTraceMiddleware::class);

        $router = $this->app->make(Router::class);
        $router->get('/global-dedupe', function (): string {
            TestExecutionTrace::push('action');

            return 'ok';
        });

        $kernel->setMiddlewares([
            'global-trace',
            TestGlobalTraceMiddleware::class,
        ]);
        $kernel->handle(Request::create('/global-dedupe'));

        self::assertSame([
            'global-before',
            'action',
            'global-after',
        ], TestExecutionTrace::all());
    }

    public function test_it_deduplicates_group_and_route_middlewares_after_alias_resolution(): void
    {
        TestExecutionTrace::reset();

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('route-trace', TestRouteTraceMiddleware::class);
        $router->group([
            'middleware' => ['route-trace', TestRouteTraceMiddleware::class],
        ], function (Router $router): void {
            $router->get('/route-dedupe', function (): string {
                TestExecutionTrace::push('action');

                return 'ok';
            })->middleware('route-trace');
        });

        $this->app->make(HttpKernel::class)->handle(Request::create('/route-dedupe'));

        self::assertSame([
            'route-before',
            'action',
            'route-after',
        ], TestExecutionTrace::all());
    }

    public function test_it_merges_group_and_route_metadata_and_exposes_it_on_the_request(): void
    {
        $router = $this->app->make(Router::class);
        $router->group([
            'metadata' => [
                'auth' => 'session',
                'runtime' => 'spa',
                'csrf' => true,
            ],
        ], function (Router $router): void {
            $router->get('/metadata', function (Request $request): array {
                return [
                    'all' => $request->routeMetadata(),
                    'auth' => $request->routeMeta('auth'),
                    'runtime' => $request->routeMeta('runtime'),
                    'csrf' => $request->routeMeta('csrf'),
                    'pipelineAuth' => $request->attribute('_pipeline_auth'),
                ];
            })
                ->name('meta.route')
                ->meta('runtime', 'ssr')
                ->guest()
                ->throttle('api')
                ->middleware(TestMetadataHeaderMiddleware::class);
        });

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/metadata'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('application/json; charset=UTF-8', $response->headers()['Content-Type']);
        self::assertSame('{"all":{"name":"meta.route","methods":["GET"],"domain":null,"middleware":["VoltStack\\\\Test\\\\Feature\\\\TestMetadataHeaderMiddleware"],"auth":"session","runtime":"ssr","csrf":true,"guest":true,"throttle":"api"},"auth":"session","runtime":"ssr","csrf":true,"pipelineAuth":"session"}', $response->content());
    }

    public function test_it_returns_a_not_found_response_when_no_route_matches(): void
    {
        $kernel = $this->app->make(HttpKernel::class);
        $response = $kernel->handle(Request::create('/missing'));

        self::assertSame(404, $response->statusCode());
        self::assertStringContainsString('Page Not Found', $response->content());
        self::assertSame('text/html; charset=UTF-8', $response->headers()['Content-Type']);
        self::assertStringContainsString('<meta name="volt-document" content="reload"', $response->content());
        self::assertStringContainsString('<meta name="volt-navigation-mode" content="reload"', $response->content());
        self::assertStringContainsString('<body data-volt-document="reload">', $response->content());
        self::assertStringNotContainsString('data-volt-navigation-mode="auto"', $response->content());
    }

    public function test_it_returns_method_not_allowed_with_allow_header_when_path_exists_for_other_methods(): void
    {
        $router = $this->app->make(Router::class);
        $router->post('/submit', fn() => 'stored');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/submit', 'GET'));

        self::assertSame(405, $response->statusCode());
        self::assertSame('POST, OPTIONS', $response->headers()['Allow']);
        self::assertStringContainsString('Method Not Allowed', $response->content());
    }

    public function test_it_falls_back_to_get_routes_for_head_requests(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/headable', fn() => new Response('body', 200, [
            'X-Route' => 'get',
        ]));

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/headable', 'HEAD'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('', $response->content());
        self::assertSame('get', $response->headers()['X-Route']);
    }

    public function test_it_prefers_explicit_head_routes_over_get_fallback(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/head-explicit', fn() => new Response('get-body', 200, [
            'X-Route' => 'get',
        ]));
        $router->head('/head-explicit', fn() => new Response('head-body', 200, [
            'X-Route' => 'head',
        ]));

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/head-explicit', 'HEAD'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('', $response->content());
        self::assertSame('head', $response->headers()['X-Route']);
    }

    public function test_it_returns_automatic_options_responses_with_allow_header(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/options-target', fn() => 'ok');
        $router->post('/options-target', fn() => 'stored');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/options-target', 'OPTIONS'));

        self::assertSame(204, $response->statusCode());
        self::assertSame('', $response->content());
        self::assertSame('GET, HEAD, POST, OPTIONS', $response->headers()['Allow']);
    }

    public function test_it_dispatches_method_override_from_form_input(): void
    {
        $router = $this->app->make(Router::class);
        $router->patch('/users/42', fn() => 'patched');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create(
            '/users/42',
            'POST',
            [],
            ['_method' => 'patch'],
        ));

        self::assertSame(200, $response->statusCode());
        self::assertSame('patched', $response->content());
    }

    public function test_it_dispatches_method_override_from_header(): void
    {
        $router = $this->app->make(Router::class);
        $router->delete('/users/42', fn() => 'deleted');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create(
            '/users/42',
            'POST',
            [],
            [],
            [],
            [],
            [],
            ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'delete'],
        ));

        self::assertSame(200, $response->statusCode());
        self::assertSame('deleted', $response->content());
    }

    public function test_it_rejects_duplicate_routes_during_registration(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/users', fn() => 'first');

        $this->expectException(DuplicateRouteException::class);
        $this->expectExceptionMessage('A route is already registered for [GET] /users.');

        $router->get('/users', fn() => 'second');
    }

    public function test_it_rejects_duplicate_route_names_during_registration_flow(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/users', fn() => 'first')->name('users.index');

        $this->expectException(DuplicateRouteNameException::class);
        $this->expectExceptionMessage('A route is already registered with the name [users.index].');

        $router->post('/users', fn() => 'second')->name('users.index');
    }

    public function test_it_returns_not_found_when_a_route_constraint_does_not_match(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/users/{id}', fn(string $id) => $id)->whereNumber('id');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/users/abc'));

        self::assertSame(404, $response->statusCode());
        self::assertStringContainsString('Page Not Found', $response->content());
    }

    public function test_it_can_match_routes_bound_to_a_static_domain(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/dashboard', fn() => 'admin')->domain('admin.example.com');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/dashboard', 'GET', [], [], [], [], [], [
            'HTTP_HOST' => 'admin.example.com',
        ]));

        self::assertSame(200, $response->statusCode());
        self::assertSame('admin', $response->content());
    }

    public function test_it_can_resolve_domain_parameters_into_route_arguments(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/dashboard', fn(string $tenant) => 'tenant:' . $tenant)
            ->domain('{tenant}.example.com')
            ->whereAlphaNumeric('tenant');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/dashboard', 'GET', [], [], [], [], [], [
            'HTTP_HOST' => 'acme42.example.com',
        ]));

        self::assertSame(200, $response->statusCode());
        self::assertSame('tenant:acme42', $response->content());
    }

    public function test_it_renders_server_errors_as_reload_only_documents(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/boom', static function (): never {
            throw new \RuntimeException('Boom');
        });

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/boom'));

        self::assertSame(500, $response->statusCode());
        self::assertStringContainsString('Server Error', $response->content());
        self::assertStringContainsString('<meta name="volt-document" content="reload"', $response->content());
        self::assertStringContainsString('<meta name="volt-navigation-mode" content="reload"', $response->content());
        self::assertStringContainsString('<body data-volt-document="reload">', $response->content());
        self::assertStringNotContainsString('data-volt-navigation-mode="auto"', $response->content());
    }

    public function test_it_bootstraps_document_contract_markers_for_full_html_documents(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/document', fn() => '<!DOCTYPE html><html><body><main>Document</main></body></html>');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/document'));

        self::assertStringContainsString('<body data-volt-document="spa" data-volt-navigation-mode="auto">', $response->content());
        self::assertStringContainsString('data-volt-runtime="true"', $response->content());
        self::assertMatchesRegularExpression('/<script data-volt-runtime="true" src="\/_volt\/runtime\.js\?v=\d+" defer><\/script>/', $response->content());
    }

    public function test_it_reuses_runtime_route_metadata_when_bootstrapping_an_html_document(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/document-runtime-metadata', fn() => '<!DOCTYPE html><html><body><main>Document</main></body></html>')
            ->meta([
                'runtime' => [
                    'document' => 'reload',
                    'layout' => 'app-shell',
                    'navigation' => 'reload',
                    'hydrate' => [
                        'enabled' => true,
                        'strategy' => 'partial',
                        'dirtyState' => 'defer',
                    ],
                    'transition' => [
                        'name' => 'fade',
                        'profile' => 'smooth',
                        'duration' => 240,
                        'mode' => 'in-out',
                    ],
                ],
            ]);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/document-runtime-metadata'));

        self::assertStringContainsString(
            '<body data-volt-document="reload" data-volt-navigation-mode="reload" data-volt-layout="app-shell" data-volt-page-transition="fade" data-volt-page-transition-profile="smooth" data-volt-page-transition-duration="240" data-volt-page-transition-mode="in-out" data-volt-hydrate="true" data-volt-hydrate-strategy="partial" data-volt-hydrate-dirty-state="defer">',
            $response->content(),
        );
        self::assertStringContainsString('data-volt-runtime="true"', $response->content());
    }

    public function test_it_reuses_rehydrated_metadata_artifacts_when_bootstrapping_an_html_document(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/artifact-document-runtime-metadata', TestHtmlDocumentController::class . '@show')
            ->name('artifact.document.runtime')
            ->meta([
                'runtime' => [
                    'document' => 'reload',
                    'layout' => 'app-shell',
                    'navigation' => 'reload',
                    'hydrate' => [
                        'enabled' => true,
                        'strategy' => 'partial',
                        'dirtyState' => 'defer',
                    ],
                    'transition' => [
                        'name' => 'fade',
                        'profile' => 'smooth',
                        'duration' => 240,
                        'mode' => 'in-out',
                    ],
                ],
            ]);

        $this->app->make(CollectionArtifactStore::class)->compileAndWrite($router);
        $this->app->make(MetadataArtifactStore::class)->compileAndWrite($router);
        $this->clearCollectionArtifactMetadata('artifact.document.runtime');

        $router->reloadCollectionArtifacts();

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/artifact-document-runtime-metadata'));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString(
            '<body data-volt-document="reload" data-volt-navigation-mode="reload" data-volt-layout="app-shell" data-volt-page-transition="fade" data-volt-page-transition-profile="smooth" data-volt-page-transition-duration="240" data-volt-page-transition-mode="in-out" data-volt-hydrate="true" data-volt-hydrate-strategy="partial" data-volt-hydrate-dirty-state="defer">',
            $response->content(),
        );
        self::assertStringContainsString('data-volt-runtime="true"', $response->content());
    }

    public function test_it_preserves_explicit_html_page_transition_options_over_runtime_metadata(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/document-runtime-transition-explicit', fn() => '<!DOCTYPE html><html><body data-volt-page-transition="slide" data-volt-page-transition-profile="cinematic" data-volt-page-transition-duration="360" data-volt-page-transition-mode="out-in"><main>Document</main></body></html>')
            ->meta([
                'runtime' => [
                    'transition' => [
                        'name' => 'fade',
                        'profile' => 'smooth',
                        'duration' => 240,
                        'mode' => 'in-out',
                    ],
                ],
            ]);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/document-runtime-transition-explicit'));

        self::assertStringContainsString(
            '<body data-volt-page-transition="slide" data-volt-page-transition-profile="cinematic" data-volt-page-transition-duration="360" data-volt-page-transition-mode="out-in" data-volt-document="spa" data-volt-navigation-mode="auto">',
            $response->content(),
        );
    }

    public function test_it_projects_runtime_hydrate_metadata_without_overriding_explicit_html_hydration_markers(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/document-runtime-hydrate-explicit', fn() => '<!DOCTYPE html><html><body data-volt-hydrate="false" data-volt-hydrate-strategy="islands" data-volt-hydrate-dirty-state="eager"><main>Document</main></body></html>')
            ->meta([
                'runtime' => [
                    'hydrate' => [
                        'enabled' => true,
                        'strategy' => 'partial',
                        'dirtyState' => 'defer',
                    ],
                ],
            ]);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/document-runtime-hydrate-explicit'));

        self::assertStringContainsString(
            '<body data-volt-hydrate="false" data-volt-hydrate-strategy="islands" data-volt-hydrate-dirty-state="eager" data-volt-document="spa" data-volt-navigation-mode="auto">',
            $response->content(),
        );
    }

    public function test_it_projects_runtime_layout_metadata_without_overriding_explicit_html_layout_markers(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/document-runtime-layout-explicit', fn() => '<!DOCTYPE html><html><body data-volt-layout="explicit-shell"><main>Document</main></body></html>')
            ->meta([
                'runtime' => [
                    'layout' => 'app-shell',
                ],
            ]);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/document-runtime-layout-explicit'));

        self::assertStringContainsString(
            '<body data-volt-layout="explicit-shell" data-volt-document="spa" data-volt-navigation-mode="auto">',
            $response->content(),
        );
    }

    public function test_it_serves_the_runtime_as_a_cacheable_javascript_asset(): void
    {
        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/_volt/runtime.js'));

        self::assertSame(200, $response->statusCode());
        self::assertSame('application/javascript; charset=UTF-8', $response->headers()['Content-Type']);
        self::assertSame('public, max-age=31536000, immutable', $response->headers()['Cache-Control']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
        self::assertArrayHasKey('ETag', $response->headers());
        self::assertArrayHasKey('Last-Modified', $response->headers());
        self::assertStringContainsString('window.Volt.contract=Object.freeze({version:2', $response->content());
        self::assertStringContainsString('window.Volt.components=', $response->content());
        self::assertStringContainsString('volt:component-destroyed', $response->content());
        self::assertStringContainsString('window.Volt.visit=function', $response->content());
        self::assertStringContainsString('window.Volt.telemetry=', $response->content());
        self::assertStringContainsString('response.headers.get("X-Volt-Navigation")', $response->content());
        self::assertStringContainsString('VoltStack SPA Routing', $response->content());
        self::assertStringContainsString('payloadLayout=', $response->content());
        self::assertStringContainsString('shouldFallbackForLayoutChange(payload.document,payloadLayout)', $response->content());
        self::assertStringContainsString('payloadHydrate=', $response->content());
        self::assertStringContainsString('payload.documentContract&&', $response->content());
        self::assertStringContainsString('documentContractForDocument', $response->content());
        self::assertStringContainsString('navigationModeForElement', $response->content());
        self::assertStringContainsString('"/_volt/routes-manifest.json"', $response->content());
        self::assertStringContainsString('resolveFrontendManifestRoute(normalizedUrl,"GET")', $response->content());
        self::assertStringContainsString('manifest-policy-reload', $response->content());
        self::assertStringContainsString('manifest-prefetch-disabled', $response->content());
        self::assertStringContainsString('"object"==typeof payload.pageTransition', $response->content());
        self::assertStringContainsString('hydrateEnabled:', $response->content());
        self::assertStringContainsString('hydrateSource:', $response->content());
        self::assertStringContainsString('data-volt-hydrate', $response->content());
        self::assertStringContainsString('"data-volt-hydrate"', $response->content());
        self::assertStringContainsString('"data-volt-hydrate",', $response->content());
    }

    public function test_it_serves_the_frontend_route_manifest_as_a_cacheable_json_asset(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/manifest-users/{user}', TestStringController::class . '@show')
            ->name('manifest.users.show')
            ->meta([
                'auth' => 'session',
                'prefetch' => true,
                'runtime' => [
                    'layout' => 'app-shell',
                    'transition' => [
                        'name' => 'fade',
                        'profile' => 'smooth',
                    ],
                    'hydrate' => [
                        'enabled' => true,
                        'strategy' => 'partial',
                    ],
                    'document' => 'reload',
                    'navigation' => 'reload',
                ],
            ]);
        $router->post('/manifest-users', TestStringController::class . '@show')
            ->name('manifest.users.store')
            ->meta([
                'runtime' => [
                    'transition' => 'slide',
                    'hydrate' => false,
                ],
            ]);
        $router->get('/manifest-component', TestManifestComponentPage::class)
            ->name('manifest.component')
            ->componentPage();
        $router->get('/manifest-widget', TestEmbeddableManifestComponent::class)
            ->name('manifest.widget')
            ->runtime([
                'hydrate' => true,
                'prefetch' => true,
            ])
            ->embeddableComponent();

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/_volt/routes-manifest.json'));
        /** @var array<string, mixed> $payload */
        $payload = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode());
        self::assertSame('application/json; charset=UTF-8', $response->headers()['Content-Type']);
        self::assertSame('public, max-age=0, must-revalidate', $response->headers()['Cache-Control']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
        self::assertArrayHasKey('ETag', $response->headers());
        self::assertArrayHasKey('Last-Modified', $response->headers());
        self::assertSame('VoltStack Frontend Manifest', $payload['protocol']['name'] ?? null);
        self::assertSame('1.0', $payload['protocol']['version'] ?? null);
        self::assertNotEmpty($payload['version']['checksum'] ?? null);
        $routes = is_array($payload['routes'] ?? null) ? $payload['routes'] : [];
        $showRoute = array_values(array_filter($routes, static fn(array $route): bool => ($route['name'] ?? null) === 'manifest.users.show'));
        $storeRoute = array_values(array_filter($routes, static fn(array $route): bool => ($route['name'] ?? null) === 'manifest.users.store'));
        $componentRoute = array_values(array_filter($routes, static fn(array $route): bool => ($route['name'] ?? null) === 'manifest.component'));
        $widgetRoute = array_values(array_filter($routes, static fn(array $route): bool => ($route['name'] ?? null) === 'manifest.widget'));

        self::assertCount(1, $showRoute);
        self::assertCount(1, $storeRoute);
        self::assertCount(1, $componentRoute);
        self::assertCount(1, $widgetRoute);
        self::assertSame('/manifest-users/{user}', $showRoute[0]['path'] ?? null);
        self::assertSame(['GET'], $showRoute[0]['methods'] ?? null);
        self::assertSame(['navigate', 'hydrate', 'prefetch'], $showRoute[0]['capabilities'] ?? null);
        self::assertSame('controller', $showRoute[0]['screen']['kind'] ?? null);
        self::assertSame('navigable', $showRoute[0]['screen']['mode'] ?? null);
        self::assertSame([
            'document' => 'reload',
            'navigation' => 'reload',
        ], $showRoute[0]['policy'] ?? null);
        self::assertSame([
            'layout' => 'app-shell',
            'transition' => 'fade',
            'hydrate' => true,
        ], $showRoute[0]['runtime'] ?? null);
        self::assertSame('/manifest-users', $storeRoute[0]['path'] ?? null);
        self::assertSame(['POST'], $storeRoute[0]['methods'] ?? null);
        self::assertSame([], $storeRoute[0]['capabilities'] ?? null);
        self::assertSame('controller', $storeRoute[0]['screen']['kind'] ?? null);
        self::assertSame('navigable', $storeRoute[0]['screen']['mode'] ?? null);
        self::assertSame([
            'transition' => 'slide',
            'hydrate' => false,
        ], $storeRoute[0]['runtime'] ?? null);

        self::assertSame('/manifest-component', $componentRoute[0]['path'] ?? null);
        self::assertSame(['GET'], $componentRoute[0]['methods'] ?? null);
        self::assertSame(['navigate'], $componentRoute[0]['capabilities'] ?? null);
        self::assertSame('component', $componentRoute[0]['screen']['kind'] ?? null);
        self::assertSame('navigable', $componentRoute[0]['screen']['mode'] ?? null);
        self::assertSame('/manifest-widget', $widgetRoute[0]['path'] ?? null);
        self::assertSame(['GET'], $widgetRoute[0]['methods'] ?? null);
        self::assertSame(['embed', 'hydrate'], $widgetRoute[0]['capabilities'] ?? null);
        self::assertSame('component', $widgetRoute[0]['screen']['kind'] ?? null);
        self::assertSame('embeddable', $widgetRoute[0]['screen']['mode'] ?? null);
        self::assertSame([
            'hydrate' => true,
        ], $widgetRoute[0]['runtime'] ?? null);
        self::assertStringNotContainsString('"middleware"', $response->content());
        self::assertStringNotContainsString('"auth"', $response->content());
        self::assertStringNotContainsString('"contract"', $response->content());
    }

    public function test_it_renders_an_embeddable_component_route_as_a_runtime_fragment(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/component-fragment', TestEmbeddableManifestComponent::class)
            ->name('component.fragment')
            ->embeddableComponent();

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/component-fragment'));

        self::assertSame(200, $response->statusCode());
        self::assertStringContainsString('data-volt-root="true"', $response->content());
        self::assertStringContainsString('fragment-widget', $response->content());
        self::assertStringNotContainsString('data-volt-runtime="true"', $response->content());
        self::assertStringNotContainsString('<body', $response->content());
    }

    public function test_it_emits_a_minimal_spa_navigation_payload_for_volt_navigation_requests(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/spa-navigation/users/{user}', TestStringController::class . '@show')
            ->name('spa.navigation.users.show')
            ->meta([
                'runtime' => [
                    'document' => 'reload',
                    'navigation' => 'reload',
                    'layout' => 'app-shell',
                    'transition' => [
                        'name' => 'fade',
                        'profile' => 'smooth',
                    ],
                    'hydrate' => [
                        'enabled' => true,
                        'strategy' => 'partial',
                    ],
                ],
            ]);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create(
            '/spa-navigation/users/15',
            'GET',
            [],
            [],
            [],
            [],
            [],
            [
                'HTTP_X_REQUESTED_WITH' => 'VoltStack',
                'HTTP_X_VOLT_NAVIGATE' => 'true',
            ],
        ));
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) ($response->headers()['X-Volt-Navigation'] ?? ''), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode());
        self::assertSame('VoltStack SPA Routing', $payload['protocol']['name'] ?? null);
        self::assertSame('1.0', $payload['protocol']['version'] ?? null);
        self::assertSame('/spa-navigation/users/15', $payload['navigation']['target'] ?? null);
        self::assertSame('GET', $payload['navigation']['method'] ?? null);
        self::assertSame('spa.navigation.users.show', $payload['screen']['route'] ?? null);
        self::assertSame('reload', $payload['policy']['document'] ?? null);
        self::assertSame('reload', $payload['policy']['navigation'] ?? null);
        self::assertSame('app-shell', $payload['runtime']['layout'] ?? null);
        self::assertSame('fade', $payload['runtime']['transition'] ?? null);
        self::assertTrue($payload['runtime']['hydrate'] ?? false);
        self::assertNull($payload['redirect'] ?? null);
        self::assertNull($payload['error'] ?? null);
    }

    public function test_it_emits_redirect_information_in_the_spa_navigation_payload(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/spa-navigation/login-redirect', fn() => new RedirectResponse('/login'))
            ->name('spa.navigation.login.redirect')
            ->meta([
                'runtime' => [
                    'layout' => 'auth-shell',
                    'hydrate' => false,
                ],
            ]);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create(
            '/spa-navigation/login-redirect',
            'GET',
            [],
            [],
            [],
            [],
            [],
            [
                'HTTP_X_REQUESTED_WITH' => 'VoltStack',
                'HTTP_X_VOLT_NAVIGATE' => 'true',
            ],
        ));
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) ($response->headers()['X-Volt-Navigation'] ?? ''), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(302, $response->statusCode());
        self::assertSame('/login', $payload['navigation']['target'] ?? null);
        self::assertSame('spa.navigation.login.redirect', $payload['screen']['route'] ?? null);
        self::assertSame('auth-shell', $payload['runtime']['layout'] ?? null);
        self::assertFalse($payload['runtime']['hydrate'] ?? true);
        self::assertSame([
            'location' => '/login',
            'status' => 302,
        ], $payload['redirect'] ?? null);
        self::assertNull($payload['error'] ?? null);
    }

    public function test_it_emits_error_information_in_the_spa_navigation_payload(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/spa-navigation/boom', static function (): never {
            throw new \RuntimeException('Boom');
        })->name('spa.navigation.boom');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create(
            '/spa-navigation/boom',
            'GET',
            [],
            [],
            [],
            [],
            [],
            [
                'HTTP_X_REQUESTED_WITH' => 'VoltStack',
                'HTTP_X_VOLT_NAVIGATE' => 'true',
            ],
        ));
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) ($response->headers()['X-Volt-Navigation'] ?? ''), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(500, $response->statusCode());
        self::assertSame('/spa-navigation/boom', $payload['navigation']['target'] ?? null);
        self::assertSame('spa.navigation.boom', $payload['screen']['route'] ?? null);
        self::assertNull($payload['redirect'] ?? null);
        self::assertSame([
            'code' => 500,
            'message' => 'Server Error',
        ], $payload['error'] ?? null);
    }

    public function test_it_preserves_declared_document_navigation_mode_when_bootstrapping_html(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/document-reload', fn() => '<!DOCTYPE html><html><head><meta name="volt-navigation-mode" content="reload"></head><body><main>Reload</main></body></html>');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/document-reload'));

        self::assertStringContainsString('<meta name="volt-navigation-mode" content="reload">', $response->content());
        self::assertStringContainsString('<body data-volt-document="spa">', $response->content());
        self::assertStringNotContainsString('data-volt-navigation-mode="auto"', $response->content());
    }

    public function test_it_assigns_stable_keys_to_inline_head_assets_without_identity_markers(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/document-inline-head-assets', fn() => <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <script type="module">
        window.documentHeadAssetBridge = true;
    </script>
    <style>
        .document-head-asset-demo { color: rgb(15 23 42); }
    </style>
</head>
<body><main>Inline head assets</main></body>
</html>
HTML);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/document-inline-head-assets'));

        self::assertMatchesRegularExpression(
            '/<script type="module"\s+data-volt-head-key="auto-script:[a-f0-9]{40}">/i',
            $response->content(),
        );
        self::assertMatchesRegularExpression(
            '/<style\s+data-volt-head-key="auto-style:[a-f0-9]{40}">/i',
            $response->content(),
        );
    }

    public function test_it_preserves_existing_head_asset_identity_markers_when_bootstrapping_html(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/document-inline-head-assets-managed', fn() => <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <script type="module" data-volt-head-key="managed-script-bridge">
        window.documentHeadManagedBridge = true;
    </script>
    <style id="managed-style">
        .document-head-asset-managed { color: rgb(15 23 42); }
    </style>
</head>
<body><main>Managed inline head assets</main></body>
</html>
HTML);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/document-inline-head-assets-managed'));

        self::assertStringContainsString('data-volt-head-key="managed-script-bridge"', $response->content());
        self::assertSame(1, substr_count($response->content(), 'data-volt-head-key="managed-script-bridge"'));
        self::assertStringContainsString('<style id="managed-style">', $response->content());
        self::assertStringNotContainsString('auto-style:', $response->content());
    }

    public function test_it_applies_head_asset_hardening_before_emitting_volt_navigation_metadata(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/document-inline-head-assets-navigation', fn() => <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <script>
        window.documentHeadNavigationBridge = true;
    </script>
</head>
<body><main>Navigation inline head assets</main></body>
</html>
HTML)->meta(['name' => 'document.inline.head.assets.navigation']);

        $response = $this->app->make(HttpKernel::class)->handle(Request::create(
            '/document-inline-head-assets-navigation',
            'GET',
            [],
            [],
            [],
            [],
            [],
            [
                'HTTP_X_REQUESTED_WITH' => 'VoltStack',
                'HTTP_X_VOLT_NAVIGATE' => 'true',
            ],
        ));
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) ($response->headers()['X-Volt-Navigation'] ?? ''), true, 512, JSON_THROW_ON_ERROR);

        self::assertMatchesRegularExpression(
            '/<script\s+data-volt-head-key="auto-script:[a-f0-9]{40}">/i',
            $response->content(),
        );
        self::assertSame('/document-inline-head-assets-navigation', $payload['navigation']['target'] ?? null);
        self::assertSame('document.inline.head.assets.navigation', $payload['screen']['route'] ?? null);
    }

    public function test_it_marks_reload_only_documents_without_forcing_auto_navigation_mode(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/document-reload-only', fn() => '<!DOCTYPE html><html><head><meta name="volt-document" content="reload-only"></head><body><main>Reload only</main></body></html>');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/document-reload-only'));

        self::assertStringContainsString('<meta name="volt-document" content="reload-only">', $response->content());
        self::assertStringContainsString('<body data-volt-document="reload">', $response->content());
        self::assertStringNotContainsString('data-volt-navigation-mode="auto"', $response->content());
    }

    public function test_it_preserves_explicit_body_level_reload_only_document_marker(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/document-body-reload-only', fn() => '<!DOCTYPE html><html><body data-volt-document="reload"><main>Reload body</main></body></html>');

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/document-body-reload-only'));

        self::assertStringContainsString('<body data-volt-document="reload">', $response->content());
        self::assertStringNotContainsString('data-volt-navigation-mode="auto"', $response->content());
    }

    public function test_it_does_not_bootstrap_attachment_responses_as_spa_documents(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/export', fn() => new Response(
            '<!DOCTYPE html><html><body><main>Export</main></body></html>',
            200,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="report.html"',
            ],
        ));

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/export'));

        self::assertStringNotContainsString('data-volt-runtime="true"', $response->content());
        self::assertStringNotContainsString('data-volt-document="spa"', $response->content());
        self::assertSame('attachment; filename="report.html"', $response->headers()['Content-Disposition']);
    }

    public function test_it_does_not_bootstrap_non_html_download_like_responses(): void
    {
        $router = $this->app->make(Router::class);
        $router->get('/report-json', fn() => new Response(
            '{"ok":true}',
            200,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        ));

        $response = $this->app->make(HttpKernel::class)->handle(Request::create('/report-json'));

        self::assertSame('{"ok":true}', $response->content());
        self::assertStringNotContainsString('data-volt-runtime="true"', $response->content());
        self::assertSame('application/json; charset=UTF-8', $response->headers()['Content-Type']);
    }

    private function clearCollectionArtifactMetadata(string $routeName): void
    {
        $path = $this->app->cachePath('routes/collection.php');
        /** @var mixed $payload */
        $payload = require $path;

        if (! is_array($payload) || ! isset($payload['routes']) || ! is_array($payload['routes'])) {
            self::fail('Collection artifact payload is invalid during metadata override test.');
        }

        foreach ($payload['routes'] as &$route) {
            if (($route['name'] ?? null) !== $routeName) {
                continue;
            }

            $route['metadata'] = [];
            break;
        }
        unset($route);

        file_put_contents($path, "<?php\n\nreturn " . var_export($payload, true) . ";\n");
    }

    /**
     * @return array<int, string>
     */
    private function routingArtifactPaths(): array
    {
        return [
            $this->app->cachePath('routes/collection.php'),
            $this->app->cachePath('routes/tree.php'),
            $this->app->cachePath('routes/metadata.php'),
            $this->app->cachePath('routes/pipeline.php'),
            $this->app->cachePath('routes/version.php'),
        ];
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

final class TestInvokableController
{
    public function __invoke(Request $request, string $id, TestResolvedDependency $dependency): array
    {
        return [
            'id' => $id,
            'message' => $dependency->message,
            'path' => $request->path(),
        ];
    }
}

final class TestStringController
{
    public function show(): string
    {
        return 'controller-string';
    }
}

final class TestManifestComponentPage extends Component
{
    public function render(): string
    {
        return '<section>manifest-component</section>';
    }
}

final class TestEmbeddableManifestComponent extends Component
{
    public function render(): string
    {
        return '<section>fragment-widget</section>';
    }
}

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::TARGET_PARAMETER)]
final class TestExplosiveRuntimeAttribute
{
    public function __construct()
    {
        throw new \RuntimeException('Runtime should not instantiate PHP attributes while dispatching routes.');
    }
}

#[TestExplosiveRuntimeAttribute]
final class TestAttributedController
{
    #[TestExplosiveRuntimeAttribute]
    public function show(#[TestExplosiveRuntimeAttribute] string $id): string
    {
        return 'attributes:' . $id;
    }
}

final class TestHttpAttributeRoutesController
{
    #[Get('/attribute-routes/users')]
    public function index(): string
    {
        return 'attribute:get';
    }

    #[Post('/attribute-routes/users')]
    public function store(): string
    {
        return 'attribute:post';
    }

    #[Put('/attribute-routes/users/{id}')]
    public function update(string $id): string
    {
        return 'attribute:put:' . $id;
    }

    #[Patch('/attribute-routes/users/{id}')]
    public function sync(string $id): string
    {
        return 'attribute:patch:' . $id;
    }

    #[Delete('/attribute-routes/users/{id}')]
    public function destroy(string $id): string
    {
        return 'attribute:delete:' . $id;
    }
}

#[Domain('tenant.example.com')]
final class TestHttpAttributeMetadataController
{
    #[Get('/attribute-meta/users/{id}')]
    #[Name('attribute.meta.show')]
    #[Middleware(TestHeaderMiddleware::class)]
    #[Middleware(TestSecondaryHeaderMiddleware::class)]
    public function show(string $id): Response
    {
        return new Response('attribute:meta:' . $id);
    }
}

final class TestMetadataEchoController
{
    public function show(Request $request): array
    {
        return [
            'all' => $request->routeMetadata(),
            'auth' => $request->routeMeta('auth'),
            'runtime' => $request->routeMeta('runtime'),
            'csrf' => $request->routeMeta('csrf'),
            'guest' => $request->routeMeta('guest'),
        ];
    }
}

final class TestResponseController
{
    public function show(): Response
    {
        return new Response('ok');
    }
}

final class TestHtmlDocumentController
{
    public function show(): string
    {
        return '<!DOCTYPE html><html><body><main>Artifact document</main></body></html>';
    }
}

final class TestResolvedDependency
{
    public string $message = 'resolved';
}

final class TestShowUserAction extends Action
{
    public function handle(mixed ...$arguments): mixed
    {
        /** @var Request $request */
        $request = $arguments[0];
        $id = (string) ($arguments[1] ?? '');

        return 'action:' . $id . ':' . $request->path();
    }
}

final class TestHeaderMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next): mixed
    {
        $response = $next($request);

        if ($response instanceof Response) {
            $response->header('X-Middleware', 'passed');
        }

        return $response;
    }
}

final class TestSecondaryHeaderMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next): mixed
    {
        $response = $next($request);

        if ($response instanceof Response) {
            $response->header('X-Secondary-Middleware', 'passed');
        }

        return $response;
    }
}

final class TestResourceController
{
    public function index(): string
    {
        return 'index';
    }

    public function create(): string
    {
        return 'create';
    }

    public function store(): string
    {
        return 'store';
    }

    public function show(string $post): string
    {
        return 'show:' . $post;
    }

    public function edit(string $post): string
    {
        return 'edit:' . $post;
    }

    public function update(string $post): string
    {
        return 'update:' . $post;
    }

    public function destroy(string $post): string
    {
        return 'destroy:' . $post;
    }
}

final class TestNestedCommentController
{
    public function index(string $post): string
    {
        return 'comments.index:' . $post;
    }

    public function create(string $post): string
    {
        return 'comments.create:' . $post;
    }

    public function store(string $post): string
    {
        return 'comments.store:' . $post;
    }

    public function show(string $post, string $comment): string
    {
        return 'comments.show:' . $post . ':' . $comment;
    }

    public function edit(string $post, string $comment): string
    {
        return 'comments.edit:' . $post . ':' . $comment;
    }

    public function update(string $post, string $comment): string
    {
        return 'comments.update:' . $post . ':' . $comment;
    }

    public function destroy(string $post, string $comment): string
    {
        return 'comments.destroy:' . $post . ':' . $comment;
    }
}

final class TestShallowCommentController
{
    public function index(string $post): string
    {
        return 'shallow.index:' . $post;
    }

    public function create(string $post): string
    {
        return 'shallow.create:' . $post;
    }

    public function store(string $post): string
    {
        return 'shallow.store:' . $post;
    }

    public function show(string $comment): string
    {
        return 'shallow.show:' . $comment;
    }

    public function edit(string $comment): string
    {
        return 'shallow.edit:' . $comment;
    }

    public function update(string $comment): string
    {
        return 'shallow.update:' . $comment;
    }

    public function destroy(string $comment): string
    {
        return 'shallow.destroy:' . $comment;
    }
}

final class TestBindableCommentController
{
    public function show(string $post, TestBindableCommentResource $comment): string
    {
        return 'bound:' . $post . ':' . $comment->identifier();
    }
}

final class TestBindableCommentResource implements RouteBindableInterface
{
    /**
     * @var array<string, string>
     */
    private static array $records = [];

    public function __construct(
        private readonly string $identifier,
    ) {}

    /**
     * @param array<string, string> $records
     */
    public static function seed(array $records): void
    {
        self::$records = $records;
    }

    public static function resolveRouteBinding(string $value, string $parameter, Request $request): ?self
    {
        if ($parameter !== 'comment') {
            return null;
        }

        $record = self::$records[$value] ?? null;

        if (! is_string($record)) {
            return null;
        }

        return new self($record);
    }

    public function identifier(): string
    {
        return $this->identifier;
    }
}

final class TestContextHeaderMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next): mixed
    {
        $response = $next($request);

        if ($response instanceof Response) {
            $response->header('X-Route-Context', $request->routeContext());
        }

        return $response;
    }
}

final class TestExecutionTrace
{
    /**
     * @var array<int, string>
     */
    private static array $entries = [];

    public static function reset(): void
    {
        self::$entries = [];
    }

    public static function push(string $entry): void
    {
        self::$entries[] = $entry;
    }

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return self::$entries;
    }
}

final class TestGlobalTraceMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next): mixed
    {
        TestExecutionTrace::push('global-before');
        $response = $next($request);
        TestExecutionTrace::push('global-after');

        return $response;
    }
}

final class TestRouteTraceMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next): mixed
    {
        TestExecutionTrace::push('route-before');
        $response = $next($request);
        TestExecutionTrace::push('route-after');

        return $response;
    }
}

final class TestOuterGroupTraceMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next): mixed
    {
        TestExecutionTrace::push('group-outer-before');
        $response = $next($request);
        TestExecutionTrace::push('group-outer-after');

        return $response;
    }
}

final class TestInnerGroupTraceMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next): mixed
    {
        TestExecutionTrace::push('group-inner-before');
        $response = $next($request);
        TestExecutionTrace::push('group-inner-after');

        return $response;
    }
}

final class TestMetadataHeaderMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next): mixed
    {
        $request->setAttribute('_pipeline_auth', $request->routeMeta('auth', 'none'));
        $response = $next($request);

        if ($response instanceof Response) {
            $response->header('X-Route-Auth', (string) $request->routeMeta('auth', 'none'));
        }

        return $response;
    }
}
