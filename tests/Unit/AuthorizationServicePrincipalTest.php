<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Contracts\PrincipalInterface;
use Quantum\Authorization\Contracts\ServicePrincipalResolverInterface;
use Quantum\Authorization\Principal\Principal;
use Quantum\Authorization\Principal\PrincipalResolver;
use Quantum\Authorization\Principal\PrincipalType;
use Quantum\Authorization\ServicePrincipal\ConfigurableServicePrincipalResolver;
use Quantum\Http\Request;
use VoltStack\Runtime\Context\RuntimeContext;

final class AuthorizationServicePrincipalTest extends TestCase
{
    public function test_resolver_returns_null_when_no_signal_present(): void
    {
        $resolver = new ConfigurableServicePrincipalResolver(['map' => []]);

        self::assertNull($resolver->resolve(null, null));
    }

    public function test_resolver_uses_runtime_metadata_service_principal_id(): void
    {
        $metadata = [
            'service_principal.id' => 'svc-billing',
            'service_principal.type' => PrincipalType::Service->value,
            'service_principal.claims' => ['env' => 'testing'],
        ];

        $runtime = $this->createRuntimeWithMetadata($metadata);
        $resolver = new ConfigurableServicePrincipalResolver(['map' => []]);

        $principal = $resolver->resolve($runtime, null);

        self::assertInstanceOf(PrincipalInterface::class, $principal);
        self::assertSame('svc-billing', $principal->id());
        self::assertSame(PrincipalType::Service, $principal->type());
        self::assertSame('runtime_metadata', $principal->claims()['resolved_via'] ?? null);
        self::assertSame('svc-billing', $principal->claims()['service_principal_id'] ?? null);
        self::assertSame('testing', $principal->claims()['env'] ?? null);
    }

    public function test_resolver_uses_as_service_flag_via_runtime_metadata(): void
    {
        $runtime = $this->createRuntimeWithMetadata([
            'service_principal.as' => 'svc-cli-import',
        ]);

        $resolver = new ConfigurableServicePrincipalResolver([
            'map' => [
                'svc-cli-import' => [
                    'type' => PrincipalType::ApiClient->value,
                    'claims' => ['cli' => true, 'team' => 'data'],
                ],
            ],
        ]);

        $principal = $resolver->resolve($runtime, null);

        self::assertNotNull($principal);
        self::assertSame('svc-cli-import', $principal->id());
        self::assertSame(PrincipalType::ApiClient, $principal->type());
        self::assertSame('runtime_flag', $principal->claims()['resolved_via'] ?? null);
        self::assertTrue((bool) ($principal->claims()['cli'] ?? false));
    }

    public function test_resolver_uses_request_query_as_service_param(): void
    {
        $request = Request::create('/internal/metrics', 'GET', [], [], [], [], [], [
            'VOLT_AS_SERVICE' => 'svc-metrics',
        ]);

        $resolver = new ConfigurableServicePrincipalResolver([
            'map' => [
                'svc-metrics' => [
                    'type' => PrincipalType::Service->value,
                    'claims' => ['observability' => true],
                ],
            ],
        ]);

        $principal = $resolver->resolve(null, $request);

        self::assertNotNull($principal);
        self::assertSame('svc-metrics', $principal->id());
        self::assertSame('request_attribute', $principal->claims()['resolved_via'] ?? null);
        self::assertTrue((bool) ($principal->claims()['observability'] ?? false));
    }

    public function test_resolver_merges_claims_from_runtime_over_config_map(): void
    {
        $runtime = $this->createRuntimeWithMetadata([
            'service_principal.id' => 'svc-orders',
            'service_principal.claims' => ['owner' => 'checkout'],
        ]);

        $resolver = new ConfigurableServicePrincipalResolver([
            'map' => [
                'svc-orders' => [
                    'type' => PrincipalType::Service->value,
                    'claims' => ['owner' => 'legacy', 'scope' => 'orders'],
                ],
            ],
        ]);

        $principal = $resolver->resolve($runtime, null);

        self::assertNotNull($principal);
        // Runtime wins over config for 'owner' (merge order: config, runtime)
        self::assertSame('checkout', $principal->claims()['owner'] ?? null);
        // Config 'scope' preserved when runtime doesn't override
        self::assertSame('orders', $principal->claims()['scope'] ?? null);
    }

    public function test_principal_resolver_fail_closed_when_service_resolver_disabled(): void
    {
        // Auth manager returns null by default, no user context
        $serviceResolver = new ConfigurableServicePrincipalResolver([
            'map' => [
                'svc-history' => ['type' => PrincipalType::Service->value, 'claims' => []],
            ],
        ]);

        $runtime = $this->createRuntimeWithMetadata([
            'service_principal.as' => 'svc-history',
        ]);

        $principalResolver = new PrincipalResolver(
            auth: null,
            servicePrincipalResolver: $serviceResolver,
            servicePrincipalResolverEnabled: false,
            runtimeContext: $runtime,
            request: null,
        );

        $resolved = $principalResolver->resolve();

        // Fall back to anonymous (no auth, no service resolver enabled)
        self::assertSame(PrincipalType::Anonymous, $resolved->type());
    }

    public function test_principal_resolver_service_enabled_resolves_service_principal(): void
    {
        $serviceResolver = new ConfigurableServicePrincipalResolver([
            'map' => [
                'svc-history' => ['type' => PrincipalType::Service->value, 'claims' => []],
            ],
        ]);

        $runtime = $this->createRuntimeWithMetadata([
            'service_principal.as' => 'svc-history',
        ]);

        $principalResolver = new PrincipalResolver(
            auth: null,
            servicePrincipalResolver: $serviceResolver,
            servicePrincipalResolverEnabled: true,
            runtimeContext: $runtime,
            request: null,
        );

        $resolved = $principalResolver->resolve();

        self::assertSame('svc-history', $resolved->id());
        self::assertTrue(in_array($resolved->type(), [PrincipalType::Service, PrincipalType::ApiClient], true));
    }

    private function createRuntimeWithMetadata(array $metadata): RuntimeContext
    {
        return new RuntimeContext(
            'svc-principal-test-request',
            Request::create('/internal/health'),
            microtime(true),
            $metadata,
        );
    }
}
