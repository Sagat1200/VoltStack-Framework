<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Routing\CompiledRouteCollection;
use Quantum\Routing\CompiledRoute;
use Quantum\Routing\Exceptions\RouteCompilationException;
use Quantum\Routing\Exceptions\DuplicateRouteException;
use Quantum\Routing\Exceptions\DuplicateRouteNameException;
use Quantum\Routing\Route;
use Quantum\Routing\RouteCollection;
use Quantum\Routing\RouteDefinition;
use Quantum\Routing\RouteMatch;
use Quantum\Routing\RouteMatcher;
use Quantum\Http\Request;
use Quantum\Routing\Exceptions\MethodNotAllowedException;
use Quantum\Routing\Exceptions\RouteNotFoundException;

final class RouteCompilationTest extends TestCase
{
    public function test_route_definition_normalizes_methods_and_uri(): void
    {
        $definition = RouteDefinition::make(['get', 'post', 'GET'], 'users/{id}', 'handler');

        self::assertSame(['GET', 'POST'], $definition->methods());
        self::assertSame('/users/{id}', $definition->path());
        self::assertSame('/users/{id}', $definition->uri());
        self::assertSame('handler', $definition->action());
        self::assertNull($definition->name());
    }

    public function test_compiled_route_exposes_path_as_an_explicit_alias_of_uri(): void
    {
        $route = new CompiledRoute(RouteDefinition::make(['GET'], '/users/{id}', 'handler'));

        self::assertSame('/users/{id}', $route->path());
        self::assertSame($route->uri(), $route->path());
    }

    public function test_route_definition_can_return_a_named_copy(): void
    {
        $definition = RouteDefinition::make(['GET'], '/users', 'handler')->withName('users.index');

        self::assertSame('users.index', $definition->name());
    }

    public function test_route_definition_can_return_a_domain_copy(): void
    {
        $definition = RouteDefinition::make(['GET'], '/users', 'handler')->withDomain('Admin.Example.com');

        self::assertSame('admin.example.com', $definition->domain());
    }

    public function test_route_definition_can_store_constraints(): void
    {
        $definition = RouteDefinition::make(['GET'], '/users/{id}', 'handler')
            ->withConstraint('id', '[0-9]+');

        self::assertSame(['id' => '[0-9]+'], $definition->constraints());
    }

    public function test_route_definition_can_rename_parameters_and_move_constraints(): void
    {
        $definition = RouteDefinition::make(['GET'], '/posts/{post}', 'handler')
            ->withConstraint('post', '[0-9]+')
            ->renameParameter('post', 'entry');

        self::assertSame('/posts/{entry}', $definition->uri());
        self::assertSame(['entry' => '[0-9]+'], $definition->constraints());
        self::assertSame(['post' => 'entry'], $definition->metadata()['parameter_aliases']);
    }

    public function test_route_definition_can_repath_and_prune_missing_parameter_constraints_and_aliases(): void
    {
        $definition = RouteDefinition::make(['GET'], '/posts/{post}/comments/{comment}', 'handler')
            ->withConstraint('post', '[0-9]+')
            ->withConstraint('comment', '[0-9]+')
            ->renameParameter('comment', 'note')
            ->withPath('/comments/{note}');

        self::assertSame('/comments/{note}', $definition->uri());
        self::assertSame(['note' => '[0-9]+'], $definition->constraints());
        self::assertSame(['comment' => 'note'], $definition->metadata()['parameter_aliases']);
    }

    public function test_route_definition_can_return_a_methods_copy(): void
    {
        $definition = RouteDefinition::make(['get'], '/posts', 'handler')
            ->withMethods(['put', 'patch', 'PUT']);

        self::assertSame(['PUT', 'PATCH'], $definition->methods());
    }

    public function test_route_definition_can_store_and_merge_metadata(): void
    {
        $definition = RouteDefinition::make(['GET'], '/users', 'handler')
            ->withMetadata('auth', true)
            ->withMetadataBag([
                'runtime' => 'spa',
                'auth' => 'session',
            ]);

        self::assertSame([
            'auth' => 'session',
            'runtime' => 'spa',
        ], $definition->metadata());
    }

    public function test_route_can_store_an_explicit_execution_context(): void
    {
        $route = new Route(RouteDefinition::make(['GET'], '/users', 'handler'));
        $route->api();

        self::assertSame('api', $route->routeMetadata()->get('context'));
    }

    public function test_route_rejects_invalid_execution_contexts(): void
    {
        $route = new Route(RouteDefinition::make(['GET'], '/users', 'handler'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Route context [queue] is invalid. Supported contexts are [http, spa, api].');

        $route->context('queue');
    }

    public function test_compiled_route_exposes_precompiled_pattern_and_parameter_names(): void
    {
        $route = new CompiledRoute(RouteDefinition::make(['GET'], '/users/{id}/posts/{post}', 'handler'));

        self::assertSame('/^\/users\/([^\/]+)\/posts\/([^\/]+)$/', $route->pattern());
        self::assertSame(['id', 'post'], $route->parameterNames());
        self::assertSame([
            'id' => '42',
            'post' => '100',
        ], $route->matchPath('/users/42/posts/100'));
    }

    public function test_compiled_route_returns_null_when_the_path_does_not_match(): void
    {
        $route = new CompiledRoute(RouteDefinition::make(['GET'], '/users/{id}', 'handler'));

        self::assertNull($route->matchPath('/teams/42'));
    }

    public function test_compiled_route_uses_constraints_in_the_compiled_pattern(): void
    {
        $route = new Route(RouteDefinition::make(['GET'], '/users/{id}', 'handler'));
        $route->whereNumber('id');

        self::assertSame('/^\/users\/([0-9]+)$/', $route->pattern());
        self::assertSame(['id' => '42'], $route->matchPath('/users/42'));
        self::assertNull($route->matchPath('/users/abc'));
    }

    public function test_compiled_route_compiles_basic_constraints_without_leaking_inner_captures(): void
    {
        $route = new Route(RouteDefinition::make(['GET'], '/posts/{slug}/{id}', 'handler'));
        $route->where('slug', '(foo|bar)')->whereNumber('id');

        self::assertSame([
            'slug' => '(?:foo|bar)',
            'id' => '[0-9]+',
        ], $route->compiledConstraints());
        self::assertSame([
            'slug' => 'foo',
            'id' => '42',
        ], $route->matchPath('/posts/foo/42'));
    }

    public function test_compiled_route_can_match_allowed_values_with_where_in(): void
    {
        $route = new Route(RouteDefinition::make(['GET'], '/reports/{format}', 'handler'));
        $route->whereIn('format', ['csv', 'json', 'xml']);

        self::assertSame('/^\/reports\/(csv|json|xml)$/', $route->pattern());
        self::assertSame(['format' => 'json'], $route->matchPath('/reports/json'));
        self::assertNull($route->matchPath('/reports/pdf'));
    }

    public function test_compiled_route_can_match_ulids_with_where_ulid(): void
    {
        $route = new Route(RouteDefinition::make(['GET'], '/events/{event}', 'handler'));
        $route->whereUlid('event');

        self::assertSame(['event' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'], $route->matchPath('/events/01ARZ3NDEKTSV4RRFFQ69G5FAV'));
        self::assertNull($route->matchPath('/events/not-a-ulid'));
    }

    public function test_compiled_route_can_match_enum_cases_with_where_enum(): void
    {
        $route = new Route(RouteDefinition::make(['GET'], '/posts/{status}', 'handler'));
        $route->whereEnum('status', TestPostStatus::class);

        self::assertSame(['status' => 'published'], $route->matchPath('/posts/published'));
        self::assertNull($route->matchPath('/posts/drafts'));
    }

    public function test_route_rejects_invalid_enum_constraint_classes(): void
    {
        $route = new Route(RouteDefinition::make(['GET'], '/posts/{status}', 'handler'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Route enum constraint [stdClass] must be a valid enum class.');

        $route->whereEnum('status', \stdClass::class);
    }

    public function test_compiled_route_exposes_a_formally_compiled_pipeline(): void
    {
        $route = new Route(RouteDefinition::make(['GET'], '/users', 'handler'));
        $route->middleware(['auth', 'throttle']);

        self::assertSame(['auth', 'throttle'], $route->routeMiddlewares());
        self::assertSame(['auth', 'throttle'], $route->routePipeline()->middlewares());
        self::assertNotSame('', $route->routePipeline()->id());
    }

    public function test_compiled_route_can_match_static_domains(): void
    {
        $route = new Route(RouteDefinition::make(['GET'], '/dashboard', 'handler'));
        $route->domain('admin.example.com');

        self::assertSame([], $route->matchHost('admin.example.com'));
        self::assertNull($route->matchHost('app.example.com'));
    }

    public function test_compiled_route_can_extract_domain_parameters(): void
    {
        $route = new Route(RouteDefinition::make(['GET'], '/dashboard', 'handler'));
        $route->domain('{tenant}.example.com')->whereAlphaNumeric('tenant');

        self::assertSame(['tenant' => 'acme42'], $route->matchHost('acme42.example.com'));
        self::assertNull($route->matchHost('acme-42.example.com'));
    }

    public function test_route_collection_registers_routes_in_deterministic_order(): void
    {
        $collection = new RouteCollection();
        $first = new Route(RouteDefinition::make(['GET'], '/first', 'first'));
        $second = new Route(RouteDefinition::make(['POST'], '/second', 'second'));

        $collection->add($first);
        $collection->add($second);

        self::assertCount(2, $collection);
        self::assertSame([$first, $second], $collection->all());
    }

    public function test_route_collection_can_materialize_a_compiled_route_collection(): void
    {
        $collection = new RouteCollection();
        $first = $collection->add(new Route(RouteDefinition::make(['GET'], '/first', 'first')));
        $second = $collection->add(new Route(RouteDefinition::make(['POST'], '/second', 'second')));
        $second->name('second.route');

        $compiled = $collection->compiled();

        self::assertInstanceOf(CompiledRouteCollection::class, $compiled);
        self::assertCount(2, $compiled);
        self::assertSame([$first, $second], $compiled->all());
        self::assertSame($second, $compiled->named('second.route'));
    }

    public function test_route_collection_rejects_malformed_uri_placeholders_when_compiling(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route(RouteDefinition::make(['GET'], '/users/{id', 'handler')));

        $this->expectException(RouteCompilationException::class);
        $this->expectExceptionMessage('Route [/users/{id] contains malformed uri placeholders.');

        $collection->compiled();
    }

    public function test_route_collection_rejects_duplicate_route_parameters_when_compiling(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route(RouteDefinition::make(['GET'], '/users/{id}/{id}', 'handler')));

        $this->expectException(RouteCompilationException::class);
        $this->expectExceptionMessage('Route [/users/{id}/{id}] contains duplicate route parameter [id].');

        $collection->compiled();
    }

    public function test_route_rejects_invalid_constraint_patterns_when_applying_basic_constraint_compilation(): void
    {
        $collection = new RouteCollection();
        $route = $collection->add(new Route(RouteDefinition::make(['GET'], '/users/{id}', 'handler')));

        $this->expectException(RouteCompilationException::class);
        $this->expectExceptionMessage('Route [/users/{id}] contains an invalid constraint pattern for [id].');

        $route->where('id', '[0-9+');
    }

    public function test_route_collection_rejects_duplicate_method_and_uri_pairs(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route(RouteDefinition::make(['GET'], '/users', 'first')));

        $this->expectException(DuplicateRouteException::class);
        $this->expectExceptionMessage('A route is already registered for [GET] /users.');

        $collection->add(new Route(RouteDefinition::make(['GET'], '/users', 'second')));
    }

    public function test_route_collection_rejects_overlapping_methods_from_multi_method_routes(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route(RouteDefinition::make(['GET', 'POST'], '/users', 'first')));

        $this->expectException(DuplicateRouteException::class);
        $this->expectExceptionMessage('A route is already registered for [POST] /users.');

        $collection->add(new Route(RouteDefinition::make(['POST', 'PUT'], '/users', 'second')));
    }

    public function test_route_collection_reindexes_signatures_when_route_methods_change(): void
    {
        $collection = new RouteCollection();
        $route = $collection->add(new Route(RouteDefinition::make(['GET'], '/users', 'first')));

        $route->remethod(['POST']);
        $collection->add(new Route(RouteDefinition::make(['GET'], '/users', 'second')));

        self::assertCount(2, $collection);
    }

    public function test_route_collection_allows_same_method_and_path_on_different_domains(): void
    {
        $collection = new RouteCollection();

        $collection->add((new Route(RouteDefinition::make(['GET'], '/users', 'first')))->domain('admin.example.com'));
        $collection->add((new Route(RouteDefinition::make(['GET'], '/users', 'second')))->domain('app.example.com'));

        self::assertCount(2, $collection);
    }

    public function test_route_collection_can_index_routes_by_name(): void
    {
        $collection = new RouteCollection();
        $route = $collection->add(new Route(RouteDefinition::make(['GET'], '/users', 'handler')));
        $route->name('users.index');

        self::assertSame('users.index', $route->name());
        self::assertSame($route, $collection->named('users.index'));
    }

    public function test_route_collection_rejects_duplicate_route_names(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route(RouteDefinition::make(['GET'], '/users', 'first')))->name('users.index');
        $route = $collection->add(new Route(RouteDefinition::make(['POST'], '/users', 'second')));

        $this->expectException(DuplicateRouteNameException::class);
        $this->expectExceptionMessage('A route is already registered with the name [users.index].');

        $route->name('users.index');
    }

    public function test_route_match_exposes_route_parameters_and_resolution_metadata(): void
    {
        $route = new Route(RouteDefinition::make(['GET'], '/users/{id}', 'handler'));
        $route->name('users.show');
        $route->domain('api.example.com');
        $route->middleware('auth');
        $route->spa()->meta([
            'auth' => true,
            'runtime' => 'spa',
        ]);

        $match = new RouteMatch($route, ['id' => '42'], 'GET');

        self::assertSame($route, $match->route());
        self::assertSame(['id' => '42'], $match->parameters());
        self::assertSame('GET', $match->resolvedMethod());
        self::assertFalse($match->usedHeadFallback());
        self::assertSame([
            'name' => 'users.show',
            'methods' => ['GET'],
            'domain' => 'api.example.com',
            'middleware' => ['auth'],
            'context' => 'spa',
            'auth' => true,
            'runtime' => 'spa',
        ], $match->metadata()->all());
        self::assertSame('spa', $match->metadata()->get('context'));
        self::assertSame('spa', $match->metadata()->get('runtime'));
    }

    public function test_route_mfa_helper_stores_step_up_metadata(): void
    {
        $route = new Route(RouteDefinition::make(['GET'], '/admin', 'handler'));
        $route->middleware('auth')->mfa();

        self::assertSame(true, $route->routeMetadata()->get('mfa'));
    }

    public function test_route_matcher_returns_a_route_match_for_dynamic_routes(): void
    {
        $collection = new RouteCollection();
        $route = $collection->add(new Route(RouteDefinition::make(['GET'], '/users/{id}', 'handler')));

        $match = (new RouteMatcher())->match(Request::create('/users/42'), $collection->compiled());

        self::assertSame($route, $match->route());
        self::assertSame(['id' => '42'], $match->parameters());
        self::assertSame('GET', $match->resolvedMethod());
    }

    public function test_route_matcher_does_not_use_reflection_when_matching_attributed_controller_routes(): void
    {
        $collection = new RouteCollection();
        $route = $collection->add(new Route(RouteDefinition::make([
            'GET',
        ], '/users/{id}', TestAttributedMatcherController::class . '@show')));

        $match = (new RouteMatcher())->match(Request::create('/users/42'), $collection->compiled());

        self::assertSame($route, $match->route());
        self::assertSame(['id' => '42'], $match->parameters());
    }

    public function test_route_matcher_does_not_use_reflection_when_matching_through_the_compiled_tree(): void
    {
        $collection = new RouteCollection();
        $route = $collection->add(new Route(RouteDefinition::make([
            'GET',
        ], '/users/{id}', TestAttributedMatcherController::class . '@show')));
        $compiled = $collection->compiled();
        $tree = new \Quantum\Routing\RouteMatchTree(1, [], [
            'users' => [
                2 => [0],
            ],
        ]);

        $match = (new RouteMatcher())->match(Request::create('/users/42'), $compiled, $tree);

        self::assertSame($route, $match->route());
        self::assertSame(['id' => '42'], $match->parameters());
    }

    public function test_route_matcher_marks_head_fallback_matches(): void
    {
        $collection = new RouteCollection();
        $route = $collection->add(new Route(RouteDefinition::make(['GET'], '/users', 'handler')));

        $match = (new RouteMatcher())->match(Request::create('/users', 'HEAD'), $collection->compiled());

        self::assertSame($route, $match->route());
        self::assertSame('GET', $match->resolvedMethod());
        self::assertTrue($match->usedHeadFallback());
    }

    public function test_route_matcher_throws_method_not_allowed_with_allow_header_information(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route(RouteDefinition::make(['POST'], '/users', 'handler')));

        try {
            (new RouteMatcher())->match(Request::create('/users', 'GET'), $collection->compiled());
            self::fail('Expected MethodNotAllowedException was not thrown.');
        } catch (MethodNotAllowedException $exception) {
            self::assertSame(['POST', 'OPTIONS'], $exception->allowedMethods());
            self::assertSame('POST, OPTIONS', $exception->allowHeader());
        }
    }

    public function test_route_matcher_throws_not_found_for_unknown_paths(): void
    {
        $collection = new RouteCollection();
        $collection->add(new Route(RouteDefinition::make(['GET'], '/users', 'handler')));

        $this->expectException(RouteNotFoundException::class);

        (new RouteMatcher())->match(Request::create('/teams'), $collection->compiled());
    }

    public function test_route_matcher_rejects_dynamic_routes_that_fail_constraints(): void
    {
        $collection = new RouteCollection();
        $route = $collection->add(new Route(RouteDefinition::make(['GET'], '/users/{id}', 'handler')));
        $route->whereNumber('id');

        $this->expectException(RouteNotFoundException::class);

        (new RouteMatcher())->match(Request::create('/users/abc'), $collection->compiled());
    }

    public function test_route_matcher_supports_uuid_and_slug_constraints(): void
    {
        $collection = new RouteCollection();
        $route = $collection->add(new Route(RouteDefinition::make(['GET'], '/posts/{slug}/{uuid}', 'handler')));
        $route->whereSlug('slug')->whereUuid('uuid');

        $match = (new RouteMatcher())->match(Request::create(
            '/posts/hello-world/123e4567-e89b-12d3-a456-426614174000'
        ), $collection->compiled());

        self::assertSame([
            'slug' => 'hello-world',
            'uuid' => '123e4567-e89b-12d3-a456-426614174000',
        ], $match->parameters());
    }

    public function test_route_matcher_supports_enum_constraints(): void
    {
        $collection = new RouteCollection();
        $route = $collection->add(new Route(RouteDefinition::make(['GET'], '/posts/{status}', 'handler')));
        $route->whereEnum('status', TestPostStatus::class);

        $match = (new RouteMatcher())->match(Request::create('/posts/archived'), $collection->compiled());

        self::assertSame($route, $match->route());
        self::assertSame(['status' => 'archived'], $match->parameters());
    }

    public function test_route_matcher_supports_static_domains(): void
    {
        $collection = new RouteCollection();
        $route = $collection->add(new Route(RouteDefinition::make(['GET'], '/dashboard', 'handler')));
        $route->domain('admin.example.com');

        $match = (new RouteMatcher())->match(Request::create('/dashboard', 'GET', [], [], [], [], [], [
            'HTTP_HOST' => 'admin.example.com',
        ]), $collection->compiled());

        self::assertSame($route, $match->route());
    }

    public function test_route_matcher_extracts_domain_parameters(): void
    {
        $collection = new RouteCollection();
        $route = $collection->add(new Route(RouteDefinition::make(['GET'], '/dashboard/{page}', 'handler')));
        $route->domain('{tenant}.example.com')->whereAlphaNumeric('tenant');

        $match = (new RouteMatcher())->match(Request::create('/dashboard/home', 'GET', [], [], [], [], [], [
            'HTTP_HOST' => 'acme42.example.com',
        ]), $collection->compiled());

        self::assertSame($route, $match->route());
        self::assertSame([
            'tenant' => 'acme42',
            'page' => 'home',
        ], $match->parameters());
    }

}

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::TARGET_PARAMETER)]
final class TestExplosiveMatcherAttribute
{
    public function __construct()
    {
        throw new \RuntimeException('Matcher runtime should not instantiate PHP attributes.');
    }
}

#[TestExplosiveMatcherAttribute]
final class TestAttributedMatcherController
{
    #[TestExplosiveMatcherAttribute]
    public function show(#[TestExplosiveMatcherAttribute] string $id): string
    {
        return $id;
    }
}

enum TestPostStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}