<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Metadata\MetadataAuthorizationContextEnricher;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Subject\SubjectDescriptor;
use Quantum\Authorization\Subject\SubjectType;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Routing\Route;
use Quantum\Routing\RouteDefinition;
use Quantum\Routing\RouteMatch;
use VoltStack\Framework\Application;
use Quantum\Authorization\Attributes\Authorize;
use Quantum\Authorization\Attributes\PublicAccess;

final class MetadataAuthorizationContextEnricherTest extends TestCase
{
    public function test_it_projects_authorization_metadata_into_request_context(): void
    {
        $app = new Application(sys_get_temp_dir());
        $enricher = new MetadataAuthorizationContextEnricher(
            $app->make(\Quantum\Authorization\Contracts\AuthorizationMetadataResolverInterface::class),
        );

        $route = new Route(RouteDefinition::make(
            ['GET'],
            '/enricher/documents/{document}',
            TestMetadataEnricherController::class,
        ));
        $route->authorize('documents.route-view', 'document');
        $request = new AuthorizationRequest(
            new Ability('documents.method-view'),
            new Principal('42'),
            new SubjectDescriptor(SubjectType::Scalar, 'doc-1', 'string'),
            new AuthorizationContext('req-1', attributes: [
                'route_match' => new RouteMatch($route, ['document' => 'doc-1'], 'GET'),
                'controller_definition' => new ControllerDefinition(TestMetadataEnricherController::class),
            ]),
        );

        $enriched = $enricher->enrich($request);

        self::assertTrue($enriched->context()->attribute('authorization.metadata.public'));
        self::assertCount(3, $enriched->context()->attribute('authorization.metadata.requirements', []));
        self::assertSame([
            ['ability' => 'documents.method-view', 'subject' => 'document', 'source' => 'method'],
        ], $enriched->context()->attribute('authorization.metadata.matched_requirements'));
    }
}

#[Authorize('documents.class-view')]
final class TestMetadataEnricherController
{
    #[PublicAccess]
    #[Authorize('documents.method-view', 'document')]
    public function __invoke(string $document): string
    {
        return $document;
    }
}
