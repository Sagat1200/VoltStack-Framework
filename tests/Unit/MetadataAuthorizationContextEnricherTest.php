<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Context\TenantScopeResolver;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Metadata\MetadataAuthorizationContextEnricher;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Subject\SubjectDescriptor;
use Quantum\Authorization\Subject\SubjectType;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Http\Request;
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
        self::assertNotNull($enriched->context()->attribute('authorization.metadata.payload'));
        self::assertNotSame('', (string) $enriched->context()->attribute('authorization.metadata.fingerprint'));
        self::assertCount(3, $enriched->context()->attribute('authorization.metadata.requirements', []));
        self::assertSame([
            ['ability' => 'documents.method-view', 'subject' => 'document', 'source' => 'method', 'condition' => null],
        ], $enriched->context()->attribute('authorization.metadata.matched_requirements'));
    }

    public function test_it_projects_declared_conditions_into_matched_requirements(): void
    {
        $app = new Application(sys_get_temp_dir());
        $enricher = new MetadataAuthorizationContextEnricher(
            $app->make(\Quantum\Authorization\Contracts\AuthorizationMetadataResolverInterface::class),
        );

        $route = new Route(RouteDefinition::make(
            ['GET'],
            '/enricher/conditional/{document}',
            TestMetadataConditionalEnricherController::class,
        ));
        $route->authorize('documents.route-approve', 'document', [
            'attribute' => 'risk.score',
            'type' => 'integer',
            'max' => 50,
        ]);
        $request = new AuthorizationRequest(
            new Ability('documents.route-approve'),
            new Principal('42'),
            new SubjectDescriptor(SubjectType::Scalar, 'doc-1', 'string'),
            new AuthorizationContext('req-conditional', attributes: [
                'route_match' => new RouteMatch($route, ['document' => 'doc-1'], 'GET'),
                'controller_definition' => new ControllerDefinition(TestMetadataConditionalEnricherController::class),
            ]),
        );

        $enriched = $enricher->enrich($request);
        $matched = $enriched->context()->attribute('authorization.metadata.matched_requirements');

        self::assertSame([
            [
                'ability' => 'documents.route-approve',
                'subject' => 'document',
                'source' => 'route',
                'condition' => [
                    'attribute' => 'risk.score',
                    'type' => 'integer',
                    'max' => 50,
                ],
            ],
        ], $matched);
    }

    public function test_it_normalizes_tenant_and_scope_from_request_before_projecting_metadata(): void
    {
        $app = new Application(sys_get_temp_dir());
        $enricher = new MetadataAuthorizationContextEnricher(
            $app->make(\Quantum\Authorization\Contracts\AuthorizationMetadataResolverInterface::class),
            new TenantScopeResolver(),
        );

        $route = new Route(RouteDefinition::make(
            ['GET'],
            '/enricher/tenant/{document}',
            TestMetadataConditionalEnricherController::class,
        ));
        $route->authorize('documents.route-approve', 'document');
        $request = new AuthorizationRequest(
            new Ability('documents.route-approve'),
            new Principal('42'),
            new SubjectDescriptor(SubjectType::Scalar, 'doc-1', 'string'),
            new AuthorizationContext('req-tenant', attributes: [
                'request' => Request::create('/enricher/tenant/doc-1', 'GET', [], [], [], [], [], [
                    'X-Tenant-Id' => 'acme-corp',
                ]),
                'route_match' => new RouteMatch($route, ['document' => 'doc-1'], 'GET'),
                'controller_definition' => new ControllerDefinition(TestMetadataConditionalEnricherController::class),
            ]),
        );

        $enriched = $enricher->enrich($request);

        self::assertSame('acme-corp', $enriched->context()->attribute('tenant.id'));
        self::assertSame('acme-corp', $enriched->context()->attribute('tenant_id'));
        self::assertSame('tenant:acme-corp', (string) $enriched->context()->attribute('authorization.scope'));
        self::assertSame('tenant:acme-corp', $enriched->context()->attribute('scope'));
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

final class TestMetadataConditionalEnricherController
{
    public function __invoke(string $document): string
    {
        return $document;
    }
}
