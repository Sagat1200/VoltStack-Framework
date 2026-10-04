<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\Bootstrapper;
use Quantum\Config\Bridge\ConfigAccessMode;
use Quantum\Config\Bridge\ConfigAccessRegistry;
use Quantum\Config\Bridge\ConfigBridge;
use Quantum\Config\ConfigRepository;
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
