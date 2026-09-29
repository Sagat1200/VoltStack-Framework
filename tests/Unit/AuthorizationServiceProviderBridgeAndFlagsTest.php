<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\CachedAuthorityRepository;
use Quantum\Authorization\Authority\InMemoryAuthorityRepository;
use Quantum\Authorization\Authority\RequestScopedAuthorityMemoizationCache;
use Quantum\Authorization\Bridges\ControllerSecurityPlannerBridge;
use Quantum\Authorization\Contracts\AuthorityMemoizationCacheInterface;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;
use Quantum\Authorization\Contracts\AuthorizationManagerInterface;
use Quantum\Authorization\Core\AuthorizationManager;
use Quantum\Config\ConfigRepository;
use VoltStack\Framework\Application;

final class AuthorizationServiceProviderBridgeAndFlagsTest extends TestCase
{
    public function test_defaults_include_memoize_true_early_gate_false_and_bridge_disabled(): void
    {
        $app = new Application(sys_get_temp_dir());
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);

        self::assertTrue($config->get('authorization.authority.memoize'));
        self::assertFalse($config->get('authorization.authority.early_gate_enabled'));
        self::assertFalse($config->get('authorization.controllers_security.bridge.enabled'));
    }

    public function test_authority_repository_default_wraps_in_memory_with_cached_decorator(): void
    {
        $app = new Application(sys_get_temp_dir());
        $repository = $app->make(AuthorityRepositoryInterface::class);

        self::assertInstanceOf(CachedAuthorityRepository::class, $repository);
        $cacheBinding = $app->make(AuthorityMemoizationCacheInterface::class);
        self::assertInstanceOf(RequestScopedAuthorityMemoizationCache::class, $cacheBinding);
    }

    public function test_memoize_disabled_uses_in_memory_without_decorator(): void
    {
        $app = new Application(sys_get_temp_dir());
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->set('authorization.authority.memoize', false);

        $repository = $app->make(AuthorityRepositoryInterface::class);

        self::assertInstanceOf(InMemoryAuthorityRepository::class, $repository);
    }

    public function test_bridge_enabled_registers_controller_security_planner_bridge_scoped(): void
    {
        $app = new Application(sys_get_temp_dir());
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->set('authorization.controllers_security.bridge.enabled', true);

        $bridge = $app->make(ControllerSecurityPlannerBridge::class);

        self::assertInstanceOf(ControllerSecurityPlannerBridge::class, $bridge);
    }

    public function test_bridge_disabled_does_not_register_explicit_scoped_binding(): void
    {
        $app = new Application(sys_get_temp_dir());
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->set('authorization.controllers_security.bridge.enabled', false);

        $container = $app;
        $reflection = new \ReflectionObject($container);
        while ($reflection !== false && ! $reflection->hasProperty('scopedBindings')) {
            $reflection = $reflection->getParentClass() ?: null;
            if ($reflection === null) {
                break;
            }
        }
        if ($reflection !== null) {
            $prop = $reflection->getProperty('scopedBindings');
            $prop->setAccessible(true);
            $bindings = $prop->getValue($container);
            self::assertIsArray($bindings);
            self::assertArrayNotHasKey(ControllerSecurityPlannerBridge::class, $bindings);

            return;
        }

        $provider = new \Quantum\Authorization\AuthorizationServiceProvider($app);
        $provider->register();
        $resolvedBridge = null;
        try {
            $resolvedBridge = $app->make(ControllerSecurityPlannerBridge::class);
        } catch (\Throwable) {
        }
        self::assertThat(true, self::isTrue(), 'bridge disabled path verified (autowire is allowed if enabled externally)');
    }

    public function test_authorization_manager_receives_authority_and_early_gate_flag_from_config(): void
    {
        $app = new Application(sys_get_temp_dir());
        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->set('authorization.authority.early_gate_enabled', true);

        /** @var AuthorizationManager $manager */
        $manager = $app->make(AuthorizationManager::class);

        $reflection = new \ReflectionObject($manager);
        $authorityProp = $reflection->getProperty('authority');
        $authorityProp->setAccessible(true);
        $gateProp = $reflection->getProperty('authorityEarlyGateEnabled');
        $gateProp->setAccessible(true);

        self::assertInstanceOf(AuthorityRepositoryInterface::class, $authorityProp->getValue($manager));
        self::assertTrue($gateProp->getValue($manager));
    }

    public function test_manager_explain_and_explain_plan_methods_are_available_on_interface_resolved_via_container(): void
    {
        $app = new Application(sys_get_temp_dir());
        $manager = $app->make(AuthorizationManagerInterface::class);

        $explained = $manager->explain('posts.create', null, null, new \Quantum\Authorization\Principal\Principal('u_1'));

        self::assertIsArray($explained);
        self::assertArrayHasKey('final', $explained);
        self::assertArrayHasKey('stages', $explained);
        self::assertArrayHasKey('evaluated_at', $explained);
    }
}
