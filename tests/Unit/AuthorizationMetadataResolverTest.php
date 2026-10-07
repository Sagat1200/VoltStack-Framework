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
        self::assertNotSame('', $metadata->payload()->fingerprint());
        self::assertSame([
            ['ability' => 'documents.class-view', 'subject' => null, 'source' => 'class', 'condition' => null, 'relation' => null],
            ['ability' => 'documents.method-view', 'subject' => 'document', 'source' => 'method', 'condition' => null, 'relation' => null],
            ['ability' => 'documents.route-view', 'subject' => 'document', 'source' => 'route', 'condition' => null, 'relation' => null],
        ], $metadata->requirementsAsArray());
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
            ['ability' => 'documents.route-view', 'subject' => 'document', 'source' => 'route', 'condition' => null, 'relation' => null],
        ], $metadata->requirementsAsArray());
    }

    public function test_it_preserves_conditions_declared_in_attributes_and_route_metadata(): void
    {
        $app = new Application(sys_get_temp_dir());
        $resolver = $app->make(AuthorizationMetadataResolverInterface::class);

        $route = new Route(RouteDefinition::make(
            ['GET'],
            '/resolver/conditional/{document}',
            TestAuthorizationConditionalMetadataResolverController::class,
        ));
        $route->authorize('documents.route-approve', 'document', [
            'attribute' => 'risk.score',
            'type' => 'integer',
            'max' => 50,
        ]);
        $match = new RouteMatch($route, ['document' => 'doc-1'], 'GET');

        $metadata = $resolver->resolve(
            $match,
            new ControllerDefinition(TestAuthorizationConditionalMetadataResolverController::class),
        );

        self::assertSame([
            [
                'ability' => 'documents.class-view',
                'subject' => null,
                'source' => 'class',
                'condition' => [
                    'attribute' => 'context.department',
                    'type' => 'enum',
                    'values' => ['legal'],
                ],
                'relation' => null,
            ],
            [
                'ability' => 'documents.method-view',
                'subject' => 'document',
                'source' => 'method',
                'condition' => [
                    'attribute' => 'risk.score',
                    'type' => 'integer',
                    'max' => 80,
                ],
                'relation' => null,
            ],
            [
                'ability' => 'documents.route-approve',
                'subject' => 'document',
                'source' => 'route',
                'condition' => [
                    'attribute' => 'risk.score',
                    'type' => 'integer',
                    'max' => 50,
                ],
                'relation' => null,
            ],
        ], $metadata->requirementsAsArray());
    }

    public function test_it_preserves_relations_declared_in_attributes_and_route_metadata(): void
    {
        $app = new Application(sys_get_temp_dir());
        $resolver = $app->make(AuthorizationMetadataResolverInterface::class);

        $route = new Route(RouteDefinition::make(
            ['GET'],
            '/resolver/relationship/{document}',
            TestAuthorizationRelationshipMetadataResolverController::class,
        ));
        $route->authorizeRelated('documents.route-manage', 'owner', 'document');
        $match = new RouteMatch($route, ['document' => 'doc-1'], 'GET');

        $metadata = $resolver->resolve(
            $match,
            new ControllerDefinition(TestAuthorizationRelationshipMetadataResolverController::class),
        );

        self::assertSame([
            [
                'ability' => 'documents.class-manage',
                'subject' => 'document',
                'source' => 'class',
                'condition' => null,
                'relation' => 'owner',
            ],
            [
                'ability' => 'documents.method-manage',
                'subject' => 'document',
                'source' => 'method',
                'condition' => null,
                'relation' => 'editor',
            ],
            [
                'ability' => 'documents.route-manage',
                'subject' => 'document',
                'source' => 'route',
                'condition' => null,
                'relation' => 'owner',
            ],
        ], $metadata->requirementsAsArray());
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

#[Authorize('documents.class-view', null, [
    'attribute' => 'context.department',
    'type' => 'enum',
    'values' => ['legal'],
])]
final class TestAuthorizationConditionalMetadataResolverController
{
    #[Authorize('documents.method-view', 'document', [
        'attribute' => 'risk.score',
        'type' => 'integer',
        'max' => 80,
    ])]
    public function __invoke(string $document): string
    {
        return $document;
    }
}

#[Authorize('documents.class-manage', 'document', null, 'owner')]
final class TestAuthorizationRelationshipMetadataResolverController
{
    #[Authorize('documents.method-manage', 'document', null, 'editor')]
    public function __invoke(string $document): string
    {
        return $document;
    }
}
