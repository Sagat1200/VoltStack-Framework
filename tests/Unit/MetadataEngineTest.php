<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Metadata\Contracts\MetadataEngineInterface;
use Quantum\Metadata\Contracts\MetadataProviderInterface;
use Quantum\Metadata\MetadataFragment;
use Quantum\Metadata\MetadataOrigin;
use Quantum\Metadata\MetadataProviderRegistry;
use Quantum\Metadata\MetadataRequest;
use Quantum\Metadata\Subjects\RouteMatchSubject;
use Quantum\Routing\Route;
use Quantum\Routing\RouteDefinition;
use Quantum\Routing\RouteMatch;
use VoltStack\Framework\Application;

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
}

