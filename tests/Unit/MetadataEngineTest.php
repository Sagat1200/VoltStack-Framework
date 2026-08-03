<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Metadata\Contracts\MetadataEngineInterface;
use Quantum\Metadata\Contracts\MetadataProviderInterface;
use Quantum\Metadata\Attributes\Meta;
use Quantum\Metadata\MetadataFragment;
use Quantum\Metadata\MetadataOrigin;
use Quantum\Metadata\MetadataProviderRegistry;
use Quantum\Metadata\MetadataRequest;
use Quantum\Metadata\Subjects\ControllerClassSubject;
use Quantum\Metadata\Subjects\ControllerMethodSubject;
use Quantum\Metadata\Subjects\RouteMatchSubject;
use Quantum\Routing\Route;
use Quantum\Routing\RouteDefinition;
use Quantum\Routing\RouteMatch;
use VoltStack\Framework\Application;
use Quantum\Controllers\Attributes\Interceptors;
use Quantum\Controllers\Attributes\ParameterAliases;

final class MetadataEngineTest extends TestCase
{
    public function test_it_resolves_route_metadata_through_the_engine(): void
    {
        $app = new Application(sys_get_temp_dir());
        $engine = $app->make(MetadataEngineInterface::class);

        $route = new Route(RouteDefinition::make(['GET'], '/meta', fn () => 'ok'));
        $route->meta('custom.value', 'hello');
        $match = new RouteMatch($route, [], 'GET');

        $bag = $engine->resolve(new MetadataRequest(
            subject: new RouteMatchSubject($match),
        ));

        self::assertSame('hello', $bag->get('custom.value'));
    }

    public function test_it_merges_values_using_schema_append_strategy(): void
    {
        $app = new Application(sys_get_temp_dir());
        $providers = $app->make(MetadataProviderRegistry::class);
        $providers->register(new class implements MetadataProviderInterface {
            public function name(): string
            {
                return 'explicit';
            }

            public function priority(): int
            {
                return 900;
            }

            public function supports(MetadataRequest $request): bool
            {
                return true;
            }

            public function provide(MetadataRequest $request): array
            {
                return [
                    new MetadataFragment(
                        key: 'controller.interceptors',
                        value: ['explicit'],
                        origin: new MetadataOrigin(provider: $this->name(), type: 'test'),
                        priority: $this->priority(),
                    ),
                ];
            }
        });

        $engine = $app->make(MetadataEngineInterface::class);

        $route = new Route(RouteDefinition::make(['GET'], '/meta-merge', fn () => 'ok'));
        $route->meta('controller.interceptors', ['route']);
        $match = new RouteMatch($route, [], 'GET');

        $bag = $engine->resolve(new MetadataRequest(
            subject: new RouteMatchSubject($match),
        ));

        self::assertSame(['route', 'explicit'], $bag->get('controller.interceptors'));
    }

    public function test_it_collects_attribute_metadata_for_controller_subjects(): void
    {
        $app = new Application(sys_get_temp_dir());
        $engine = $app->make(MetadataEngineInterface::class);

        $route = new Route(RouteDefinition::make(['GET'], '/meta-attr', TestMetadataController::class));
        $match = new RouteMatch($route, [], 'GET');
        $routeSubject = new RouteMatchSubject($match);
        $classSubject = new ControllerClassSubject(TestMetadataController::class, $routeSubject);
        $methodSubject = new ControllerMethodSubject(TestMetadataController::class, '__invoke', $classSubject);

        $bag = $engine->resolve(new MetadataRequest(subject: $methodSubject));

        self::assertSame('class-value', $bag->get('custom.class'));
        self::assertSame('method-value', $bag->get('custom.method'));
    }

    public function test_it_collects_reflection_metadata_for_controller_method_subject(): void
    {
        $app = new Application(sys_get_temp_dir());
        $engine = $app->make(MetadataEngineInterface::class);

        $route = new Route(RouteDefinition::make(['GET'], '/meta-reflection', TestMetadataController::class));
        $match = new RouteMatch($route, [], 'GET');
        $routeSubject = new RouteMatchSubject($match);
        $classSubject = new ControllerClassSubject(TestMetadataController::class, $routeSubject);
        $methodSubject = new ControllerMethodSubject(TestMetadataController::class, '__invoke', $classSubject);

        $bag = $engine->resolve(new MetadataRequest(subject: $methodSubject));

        self::assertSame(TestMetadataController::class, $bag->get('controller.reflection.class'));
        self::assertSame('__invoke', $bag->get('controller.reflection.method'));
    }

    public function test_it_merges_controller_interceptors_between_attributes_and_route_metadata(): void
    {
        $app = new Application(sys_get_temp_dir());
        $engine = $app->make(MetadataEngineInterface::class);

        $route = new Route(RouteDefinition::make(['GET'], '/meta-interceptors', TestMetadataController::class));
        $route->meta('controller.interceptors', ['from-route']);
        $match = new RouteMatch($route, [], 'GET');
        $routeSubject = new RouteMatchSubject($match);
        $classSubject = new ControllerClassSubject(TestMetadataController::class, $routeSubject);
        $methodSubject = new ControllerMethodSubject(TestMetadataController::class, '__invoke', $classSubject);

        $bag = $engine->resolve(new MetadataRequest(subject: $methodSubject));

        self::assertSame(['from-attribute', 'from-route'], $bag->get('controller.interceptors'));
    }

    public function test_it_supports_friendly_controller_attributes(): void
    {
        $app = new Application(sys_get_temp_dir());
        $engine = $app->make(MetadataEngineInterface::class);

        $route = new Route(RouteDefinition::make(['GET'], '/meta-friendly', TestFriendlyAttributeController::class));
        $route->meta('controller.interceptors', ['from-route']);
        $match = new RouteMatch($route, [], 'GET');
        $routeSubject = new RouteMatchSubject($match);
        $classSubject = new ControllerClassSubject(TestFriendlyAttributeController::class, $routeSubject);
        $methodSubject = new ControllerMethodSubject(TestFriendlyAttributeController::class, '__invoke', $classSubject);

        $bag = $engine->resolve(new MetadataRequest(subject: $methodSubject));

        self::assertSame(['from-friendly', 'from-route'], $bag->get('controller.interceptors'));
        self::assertSame(['user' => 'userId'], $bag->get('parameter_aliases'));
    }
}

#[Meta('custom.class', 'class-value')]
final class TestMetadataController
{
    #[Meta('custom.method', 'method-value')]
    #[Meta('controller.interceptors', ['from-attribute'])]
    public function __invoke(): string
    {
        return 'ok';
    }
}

#[Interceptors(['from-friendly'])]
#[ParameterAliases(['user' => 'userId'])]
final class TestFriendlyAttributeController
{
    public function __invoke(): string
    {
        return 'ok';
    }
}
