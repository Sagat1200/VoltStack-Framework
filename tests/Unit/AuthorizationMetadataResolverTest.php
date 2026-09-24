<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Attributes\Authorize;
use Quantum\Authorization\Attributes\PublicAccess;
use Quantum\Authorization\Contracts\AuthorizationMetadataResolverInterface;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Routing\Route;
use Quantum\Routing\RouteDefinition;
use Quantum\Routing\RouteMatch;
use VoltStack\Framework\Application;

final class AuthorizationMetadataResolverTest extends TestCase
{
    public function test_it_resolves_authorization_metadata_from_route_and_controller_layers(): void
    {
        $app = new Application(sys_get_temp_dir());
        $resolver = $app->make(AuthorizationMetadataResolverInterface::class);

        $route = new Route(RouteDefinition::make(
            ['GET'],
            '/resolver/documents/{document}',
            TestAuthorizationMetadataResolverController::class,
        ));
        $route->authorize('documents.route-view', 'document');
        $match = new RouteMatch($route, ['document' => 'doc-1'], 'GET');

        $metadata = $resolver->resolve(
            $match,
            new ControllerDefinition(TestAuthorizationMetadataResolverController::class),
        );

        self::assertTrue($metadata->public());
        self::assertSame([
            ['ability' => 'documents.class-view', 'subject' => null, 'source' => 'class'],
            ['ability' => 'documents.method-view', 'subject' => 'document', 'source' => 'method'],
            ['ability' => 'documents.route-view', 'subject' => 'document', 'source' => 'route'],
        ], $metadata->requirements());
    }

    public function test_it_resolves_route_only_authorization_metadata_without_controller_definition(): void
    {
        $app = new Application(sys_get_temp_dir());
        $resolver = $app->make(AuthorizationMetadataResolverInterface::class);

        $route = new Route(RouteDefinition::make(['GET'], '/resolver/public', fn (): string => 'ok'));
        $route->authorize('documents.route-view', 'document');
        $route->publicAccess();
        $match = new RouteMatch($route, [], 'GET');

        $metadata = $resolver->resolve($match);

        self::assertTrue($metadata->public());
        self::assertSame([
            ['ability' => 'documents.route-view', 'subject' => 'document', 'source' => 'route'],
        ], $metadata->requirements());
    }
}

#[Authorize('documents.class-view')]
final class TestAuthorizationMetadataResolverController
{
    #[PublicAccess]
    #[Authorize('documents.method-view', 'document')]
    public function __invoke(string $document): string
    {
        return $document;
    }
}
