<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\Bootstrapper;
use Quantum\Config\Bridge\ConfigAccessMode;
use Quantum\Config\Bridge\ConfigAccessRegistry;
use Quantum\Config\Bridge\ConfigBridge;
use Quantum\Config\ConfigRepository;
use Quantum\Config\Scope\ConfigurationOverrideWriter;
use Quantum\Controllers\Security\Context\ControllerSecurityContextFactory;
use Quantum\Controllers\Security\Contracts\ControllerSecurityContextFactoryInterface;
use VoltStack\Framework\Application;

final class ConfigBridgeIntegrationTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-config-bridge-' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

return [
    'env' => 'testing',
];
PHP
        );

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'cache.php',
            <<<PHP
<?php

return [
    'compiled' => [
        'views' => 'storage/framework/cache/compiled/views',
    ],
];
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_bridge_syncs_from_bootstrapper_and_exposes_registered_static_paths(): void
    {
        $app = new Application($this->basePath);
        $bootstrapper = new Bootstrapper($app);

        $bootstrapper->loadConfiguration();

        $bridge = $app->configBridge();
        $registry = $app->make(ConfigAccessRegistry::class);

        self::assertTrue($registry->has('cache.compiled.views'));
        self::assertSame(ConfigAccessMode::Static, $registry->modeFor('cache.compiled.views'));
        self::assertSame('testing', $bridge->static('app.env'));
        self::assertSame(
            'storage/framework/cache/compiled/views',
            $bridge->snapshot()->staticPaths()['cache.compiled.views'],
        );
    }

    public function test_bridge_tracks_runtime_mutations_for_static_and_scoped_paths(): void
    {
        $app = new Application($this->basePath);
        $config = $app->make(ConfigRepository::class);
        $bridge = $app->configBridge();

        $config->set('telemetry.exporter', 'jsonl');
        $config->set('controller_security.authorization.max_policy_evaluations', 21);

        self::assertSame('jsonl', $bridge->static('telemetry.exporter'));
        self::assertSame(21, $bridge->scoped('controller_security.authorization.max_policy_evaluations'));
        self::assertSame('jsonl', $bridge->snapshot()->staticPaths()['telemetry.exporter']);
        self::assertSame(
            21,
            $bridge->snapshot()->scopedPaths()['controller_security.authorization.max_policy_evaluations'],
        );
    }

    public function test_request_scoped_security_factory_reads_classified_config_through_bridge(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('controller_security.authorization.max_policy_evaluations', 13);
        $app->enterRequestScope();

        try {
            $factory = $app->make(ControllerSecurityContextFactoryInterface::class);

            self::assertInstanceOf(ControllerSecurityContextFactory::class, $factory);

            $property = new \ReflectionProperty($factory, 'defaultMaxEvaluations');
            self::assertSame(13, $property->getValue($factory));
        } finally {
            $app->leaveScope();
        }
    }

    public function test_scoped_reads_use_frozen_snapshot_and_request_overrides(): void
    {
        $app = new Application($this->basePath);
        $config = $app->make(ConfigRepository::class);
        $bridge = $app->make(ConfigBridge::class);
        $writer = $app->make(ConfigurationOverrideWriter::class);

        $config->set('controller_security.authorization.max_policy_evaluations', 13);
        $app->enterRequestScope();

        try {
            self::assertSame(13, $bridge->scoped('controller_security.authorization.max_policy_evaluations'));

            $config->set('controller_security.authorization.max_policy_evaluations', 21);

            self::assertSame(
                13,
                $bridge->scoped('controller_security.authorization.max_policy_evaluations'),
                'Scoped reads must keep the base snapshot frozen for the active request.',
            );

            $writer->set('controller_security.authorization.max_policy_evaluations', 34);

            self::assertSame(34, $bridge->scoped('controller_security.authorization.max_policy_evaluations'));
            self::assertSame(
                ['controller_security' => ['authorization' => ['max_policy_evaluations' => 34]]],
                $writer->overrides(),
            );
        } finally {
            $app->leaveScope();
        }

        $app->enterRequestScope();

        try {
            self::assertSame(
                21,
                $bridge->scoped('controller_security.authorization.max_policy_evaluations'),
                'A new request must see the latest global snapshot without leaking prior overrides.',
            );
        } finally {
            $app->leaveScope();
        }
    }

    public function test_writer_requires_an_active_runtime_scope(): void
    {
        $this->expectException(\RuntimeException::class);

        $app = new Application($this->basePath);
        $app->configWriter()->set('controller_security.authorization.max_policy_evaluations', 10);
    }

    public function test_tenant_scope_inherits_parent_effective_snapshot_and_keeps_its_own_overrides(): void
    {
        $app = new Application($this->basePath);
        $config = $app->make(ConfigRepository::class);
        $bridge = $app->make(ConfigBridge::class);
        $writer = $app->make(ConfigurationOverrideWriter::class);

        $config->set('controller_security.authorization.max_policy_evaluations', 13);
        $app->enterRequestScope();

        try {
            $writer->set('controller_security.authorization.max_policy_evaluations', 21);
            $config->set('controller_security.authorization.max_policy_evaluations', 55);

            $app->enterTenantScope();

            try {
                self::assertSame(
                    21,
                    $bridge->scoped('controller_security.authorization.max_policy_evaluations'),
                    'Tenant scope must inherit the effective snapshot frozen by its parent scope.',
                );

                $writer->set('controller_security.authorization.max_policy_evaluations', 34);

                self::assertSame(34, $bridge->scoped('controller_security.authorization.max_policy_evaluations'));
            } finally {
                $app->leaveScope();
            }

            self::assertSame(
                21,
                $bridge->scoped('controller_security.authorization.max_policy_evaluations'),
                'Tenant overrides must not leak back into the parent request scope.',
            );
        } finally {
            $app->leaveScope();
        }

        $app->enterRequestScope();

        try {
            self::assertSame(
                55,
                $bridge->scoped('controller_security.authorization.max_policy_evaluations'),
                'A new top-level request must still start from the latest global snapshot.',
            );
        } finally {
            $app->leaveScope();
        }
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
