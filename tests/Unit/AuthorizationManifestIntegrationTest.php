<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Attributes\Authorize;
use Quantum\Authorization\Attributes\PublicAccess;
use Quantum\Authorization\Contracts\AuthorizationMetadataResolverInterface;
use Quantum\Authorization\Manifest\Contracts\AuthorizationManifestStoreInterface;
use Quantum\Authorization\Metadata\MetadataAuthorizationContextEnricher;
use Quantum\Authorization\Ability\Ability;
use Quantum\Authorization\Context\AuthorizationContext;
use Quantum\Authorization\Core\AuthorizationRequest;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Subject\SubjectDescriptor;
use Quantum\Authorization\Subject\SubjectType;
use Quantum\Controllers\ControllerDefinition;
use Quantum\Routing\Route;
use Quantum\Routing\RouteDefinition;
use Quantum\Routing\RouteMatch;
use VoltStack\Framework\Application;

final class AuthorizationManifestIntegrationTest extends TestCase
{
    public function test_resolver_caches_payload_in_manifest_store(): void
    {
        $app = new Application(sys_get_temp_dir());
        $store = $app->make(AuthorizationManifestStoreInterface::class);
        $resolver = $app->make(AuthorizationMetadataResolverInterface::class);

        $route = new Route(RouteDefinition::make(
            ['GET'],
            '/manifest/documents/{document}',
            TestManifestAuthorizationController::class,
        ));
        $route->authorize('documents.route-view', 'document');
        $match = new RouteMatch($route, ['document' => 'doc-1'], 'GET');

        self::assertCount(0, array_filter(glob(sys_get_temp_dir() . '/cache/authz_*.php') ?: []));

        $first = $resolver->resolve($match, new ControllerDefinition(TestManifestAuthorizationController::class));
        $second = $resolver->resolve($match, new ControllerDefinition(TestManifestAuthorizationController::class));

        self::assertTrue($store->has($first->payload()->fingerprint()));
        self::assertSame($first->payload()->fingerprint(), $second->payload()->fingerprint());
        self::assertTrue($second->public());
        self::assertCount(3, $second->payload()->requirements());
    }

    public function test_enricher_exposes_fingerprint_and_payload_from_cached_resolver(): void
    {
        $app = new Application(sys_get_temp_dir());
        $enricher = new MetadataAuthorizationContextEnricher(
            $app->make(AuthorizationMetadataResolverInterface::class),
        );

        $route = new Route(RouteDefinition::make(
            ['GET'],
            '/manifest/documents/{document}',
            TestManifestAuthorizationController::class,
        ));
        $route->authorize('documents.route-view', 'document');
        $context = new AuthorizationContext('manifest-req', attributes: [
            'route_match' => new RouteMatch($route, ['document' => 'doc-1'], 'GET'),
            'controller_definition' => new ControllerDefinition(TestManifestAuthorizationController::class),
        ]);
        $request = new AuthorizationRequest(
            new Ability('documents.method-view'),
            new Principal('42'),
            new SubjectDescriptor(SubjectType::Scalar, 'doc-1', 'string'),
            $context,
        );

        $enriched = $enricher->enrich($request);
        $fingerprint = $enriched->context()->attribute('authorization.metadata.fingerprint');

        self::assertIsString($fingerprint);
        self::assertNotEmpty($fingerprint);
        self::assertTrue($enriched->context()->attribute('authorization.metadata.public'));
        self::assertSame(
            [['ability' => 'documents.method-view', 'subject' => 'document', 'source' => 'method']],
            $enriched->context()->attribute('authorization.metadata.matched_requirements'),
        );
    }
}

#[Authorize('documents.class-view')]
final class TestManifestAuthorizationController
{
    #[PublicAccess]
    #[Authorize('documents.method-view', 'document')]
    public function __invoke(string $document): string
    {
        return $document;
    }
}
