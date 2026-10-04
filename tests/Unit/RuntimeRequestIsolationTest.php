<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Context\AuthenticationContextAccessor;
use Quantum\Auth\Contracts\OidcJwksCacheInterface;
use Quantum\Authorization\Authority\CachedAuthorityRepository;
use Quantum\Authorization\Authority\InMemoryAuthorityRepository;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;
use Quantum\Config\ConfigRepository;
use Quantum\Config\Scope\ConfigurationOverrideWriter;
use Quantum\Controllers\ControllerExecutionContext;
use Quantum\Controllers\Security\Contracts\ControllerSecurityContextFactoryInterface;
use Quantum\Controllers\Security\Contracts\ControllerSecurityManagerInterface;
use Quantum\Controllers\Security\Decision\SecurityDecision;
use Quantum\Controllers\Security\Decision\SecurityDecisionKey;
use Quantum\Http\Request;
use Quantum\Routing\Route;
use Quantum\Routing\RouteDefinition;
use Quantum\Routing\RouteMatch;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\ScopeManager;

final class RuntimeRequestIsolationTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-runtime-request-isolation-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_authentication_context_accessor_does_not_leak_state_across_requests(): void
    {
        $app = new Application($this->basePath);
        $scopeManager = $app->make(ScopeManager::class);

        $firstContext = $scopeManager->begin(Request::create('/auth/first'));
        $firstAccessor = $app->make(AuthenticationContextAccessor::class);
        $firstAccessor->putUser(['id' => 'user-first']);

        $firstAuthContext = $firstAccessor->get();

        self::assertNotNull($firstAuthContext);
        self::assertSame('user-first', (string) $firstAuthContext->identity->identifier());
        self::assertSame($firstContext->requestId(), $firstAuthContext->requestId);

        $scopeManager->end();

        $secondContext = $scopeManager->begin(Request::create('/auth/second'));
        $secondAccessor = $app->make(AuthenticationContextAccessor::class);

        self::assertNotSame($firstAccessor, $secondAccessor);
        self::assertNull($secondAccessor->get());

        $secondAccessor->putUser(['id' => 'user-second']);
        $secondAuthContext = $secondAccessor->get();

        self::assertNotNull($secondAuthContext);
        self::assertSame('user-second', (string) $secondAuthContext->identity->identifier());
        self::assertSame($secondContext->requestId(), $secondAuthContext->requestId);
        self::assertNotSame($firstContext->requestId(), $secondContext->requestId());

        $scopeManager->end();
    }

    public function test_authority_repository_memoization_cache_is_recreated_for_each_request(): void
    {
        $app = new Application($this->basePath);
        $scopeManager = $app->make(ScopeManager::class);

        $scopeManager->begin(Request::create('/authority/first'));
        $firstRepository = $app->make(AuthorityRepositoryInterface::class);

        self::assertInstanceOf(CachedAuthorityRepository::class, $firstRepository);

        $innerRepository = $this->innerAuthorityRepository($firstRepository);
        $innerRepository->grantPermission('user-1', 'posts.create');

        $firstPermissions = array_map(
            static fn ($permission): string => (string) $permission,
            $firstRepository->effectivePermissionsForPrincipal('user-1'),
        );

        self::assertSame(['posts.create'], $firstPermissions);

        $scopeManager->end();

        $innerRepository->revokeAll('user-1');

        $scopeManager->begin(Request::create('/authority/second'));
        $secondRepository = $app->make(AuthorityRepositoryInterface::class);
        $secondPermissions = array_map(
            static fn ($permission): string => (string) $permission,
            $secondRepository->effectivePermissionsForPrincipal('user-1'),
        );

        self::assertNotSame($firstRepository, $secondRepository);
        self::assertSame([], $secondPermissions);

        $scopeManager->end();
    }

    public function test_oidc_jwks_cache_is_request_scoped_in_persistent_runtime(): void
    {
        $app = new Application($this->basePath);
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->set('auth.oidc.enabled', true);
        $config->set('auth.oidc.jwks.driver', 'memory');
        $scopeManager = $app->make(ScopeManager::class);

        $scopeManager->begin(Request::create('/oidc/first'));
        $firstCache = $app->make(OidcJwksCacheInterface::class);

        self::assertInstanceOf(OidcJwksCacheInterface::class, $firstCache);

        $firstCache->saveKey('kid-1', ['kty' => 'RSA', 'kid' => 'kid-1']);
        self::assertSame(['kty' => 'RSA', 'kid' => 'kid-1'], $firstCache->getKey('kid-1'));

        $scopeManager->end();

        $scopeManager->begin(Request::create('/oidc/second'));
        $secondCache = $app->make(OidcJwksCacheInterface::class);

        self::assertInstanceOf(OidcJwksCacheInterface::class, $secondCache);
        self::assertNotSame($firstCache, $secondCache);
        self::assertNull($secondCache->getKey('kid-1'));

        $scopeManager->end();
    }

    public function test_controller_security_factory_and_manager_are_request_scoped_and_keep_decision_cache_per_context(): void
    {
        $app = new Application($this->basePath);
        $scopeManager = $app->make(ScopeManager::class);
        $cacheKey = new SecurityDecisionKey(
            principalId: 'user-1',
            tenantId: 'tenant-a',
            policyId: 'posts.policy',
            action: 'view',
            resourceIdentity: 'post:1',
        );

        $scopeManager->begin(Request::create('/security/first'));
        $firstFactory = $app->make(ControllerSecurityContextFactoryInterface::class);
        $firstManager = $app->make(ControllerSecurityManagerInterface::class);
        $firstRequest = Request::create('/security/first');
        $firstContext = $firstFactory->create($firstRequest, $this->buildExecutionContext($firstRequest));
        $firstContext->decisions->put($cacheKey, SecurityDecision::allow('posts.policy'));

        self::assertSame(1, $firstContext->decisions->count());
        self::assertSame($firstFactory, $app->make(ControllerSecurityContextFactoryInterface::class));
        self::assertSame($firstManager, $app->make(ControllerSecurityManagerInterface::class));

        $scopeManager->end();

        $scopeManager->begin(Request::create('/security/second'));
        $secondFactory = $app->make(ControllerSecurityContextFactoryInterface::class);
        $secondManager = $app->make(ControllerSecurityManagerInterface::class);
        $secondRequest = Request::create('/security/second');
        $secondContext = $secondFactory->create($secondRequest, $this->buildExecutionContext($secondRequest));

        self::assertNotSame($firstFactory, $secondFactory);
        self::assertNotSame($firstManager, $secondManager);
        self::assertNotSame($firstContext->decisions, $secondContext->decisions);
        self::assertSame(0, $secondContext->decisions->count());
        self::assertNull($secondContext->decisions->get($cacheKey));

        $scopeManager->end();
    }

    public function test_configuration_overrides_are_isolated_per_request_scope(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('controller_security.authorization.max_policy_evaluations', 16);
        $scopeManager = $app->make(ScopeManager::class);

        $scopeManager->begin(Request::create('/config/first'));
        $writer = $app->make(ConfigurationOverrideWriter::class);
        $writer->set('controller_security.authorization.max_policy_evaluations', 32);

        self::assertSame(
            32,
            $app->configBridge()->scoped('controller_security.authorization.max_policy_evaluations'),
        );

        $scopeManager->end();

        $scopeManager->begin(Request::create('/config/second'));

        self::assertSame(
            16,
            $app->configBridge()->scoped('controller_security.authorization.max_policy_evaluations'),
        );

        $scopeManager->end();
    }

    private function buildExecutionContext(Request $request): ControllerExecutionContext
    {
        $match = new RouteMatch(
            new Route(RouteDefinition::make(['GET'], $request->uri(), 'RuntimeIsolationController@index')),
            [],
            'GET',
        );

        return new ControllerExecutionContext($request, $match);
    }

    private function innerAuthorityRepository(CachedAuthorityRepository $repository): InMemoryAuthorityRepository
    {
        $reflection = new \ReflectionObject($repository);
        $property = $reflection->getProperty('inner');
        $property->setAccessible(true);
        $inner = $property->getValue($repository);

        self::assertInstanceOf(InMemoryAuthorityRepository::class, $inner);

        return $inner;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($target)) {
                $this->deleteDirectory($target);
                continue;
            }

            unlink($target);
        }

        rmdir($path);
    }
}
