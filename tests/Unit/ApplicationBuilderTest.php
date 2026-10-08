<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\ApplicationBuilder;
use Quantum\Bootstrap\ApplicationPlan;
use Quantum\Bootstrap\Bootstrapper;
use Quantum\Bootstrap\Config\BootstrapConfiguration;
use Quantum\Bootstrap\Config\EnvReference;
use Quantum\Bootstrap\Config\SecretReference;
use Quantum\Bootstrap\Context\BootstrapContext;
use Quantum\Bootstrap\Context\BuildContext;
use Quantum\Config\ConfigRepository;
use Quantum\Config\Publication\PublishedConfigurationRequiredException;
use VoltStack\Framework\Application;
use VoltStack\Framework\ServiceProvider;

final class ApplicationBuilderTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-application-builder-' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

return [
    'name' => 'VoltStack Builder Test',
    'env' => 'local',
];
PHP
        );
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_application_builder_creates_a_sealed_immutable_plan_without_booting_runtime(): void
    {
        $builder = ApplicationBuilder::create($this->basePath)
            ->withProviders([TestPlanServiceProvider::class])
            ->withConfigDirectory($this->basePath . DIRECTORY_SEPARATOR . 'config')
            ->withEnvironment('testing')
            ->withProfile('console')
            ->withArtifactDirectory($this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bootstrap')
            ->withDiscovery(true, ['voltstack/*'])
            ->withoutDiscovery(['legacy/*']);

        $plan = $builder->build();

        self::assertInstanceOf(ApplicationPlan::class, $plan);
        self::assertTrue($builder->sealed());
        self::assertSame($plan, $builder->build(), 'build() should return the cached plan once the builder is sealed.');
        self::assertSame($this->basePath, $plan->basePath());
        self::assertSame($this->basePath . DIRECTORY_SEPARATOR . 'config', $plan->configDirectory());
        self::assertSame([TestPlanServiceProvider::class], $plan->providers());
        self::assertSame('testing', $plan->environment());
        self::assertSame('console', $plan->profile());
        self::assertSame(
            $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bootstrap',
            $plan->artifactDirectory(),
        );
        self::assertTrue($plan->discoveryEnabled());
        self::assertSame(['voltstack/*'], $plan->discoveryAllowPackages());
        self::assertSame(['legacy/*'], $plan->discoveryDenyPackages());

        $this->expectException(LogicException::class);
        $builder->withProfile('web');
    }

    public function test_bootstrapper_can_boot_an_application_plan_with_context(): void
    {
        $plan = ApplicationBuilder::create($this->basePath)
            ->withProviders([TestPlanServiceProvider::class])
            ->withEnvironment('testing')
            ->withProfile('console')
            ->withBootstrapConfiguration(BootstrapConfiguration::fromSections(
                structural: ['runtime' => ['driver' => 'frankenphp']],
                operational: ['app_env' => new EnvReference('APP_ENV', 'testing')],
                secrets: ['app_key' => new SecretReference('APP_KEY')],
            ))
            ->build();

        $context = BootstrapContext::forConsole(
            environment: 'testing',
            artifactPolicy: 'prefer-artifacts',
            executionMode: 'single',
            profile: 'console',
        );

        $app = new Application($this->basePath);
        $bootstrapper = new Bootstrapper($app);

        $bootstrapper->bootstrapPlan($plan, $context);

        self::assertTrue($app->isBooted());
        self::assertSame('VoltStack Builder Test', $app->config('app.name'));
        self::assertSame('testing', $app->environment());
        self::assertSame('booted', $app->make('test.plan.provider.state'));
        self::assertSame($plan, $app->make(ApplicationPlan::class));
        self::assertSame($context, $app->make(BootstrapContext::class));
        self::assertSame('frankenphp', $app->config('bootstrap.structural.runtime.driver'));
        self::assertSame('secret', $app->config('bootstrap.secrets.app_key.type'));
    }

    public function test_bootstrap_plan_can_require_a_published_configuration_generation(): void
    {
        $publisherApp = new Application($this->basePath);
        $publisherConfig = $publisherApp->make(ConfigRepository::class);
        $publisherConfig->set('app.name', 'Published Builder Config');
        $publisherConfig->set('app.env', 'production');

        $codec = $publisherApp->configSnapshotCodec();
        $baseSnapshot = $publisherConfig->snapshot(provenance: ['app' => 'published']);
        $artifact = $publisherApp->configManifestStore()->publish($publisherConfig->snapshot(
            provenance: $baseSnapshot->provenance(),
            configId: $codec->configId($baseSnapshot),
        ));
        $publisherApp->configManifestStore()->activateGeneration($artifact->generationId());

        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('production')
            ->withBootstrapConfiguration(BootstrapConfiguration::fromSections(
                operational: ['require_published_config' => true],
            ))
            ->build();

        $app = new Application($this->basePath);
        $bootstrapper = new Bootstrapper($app);
        $bootstrapper->bootstrapPlan($plan);

        self::assertSame('Published Builder Config', $app->config('app.name'));
        self::assertSame('production', $app->environment());
    }

    public function test_bootstrap_plan_fails_when_it_requires_published_configuration_but_none_is_active(): void
    {
        $plan = ApplicationBuilder::create($this->basePath)
            ->withBootstrapConfiguration(BootstrapConfiguration::fromSections(
                operational: ['require_published_config' => true],
            ))
            ->build();

        $app = new Application($this->basePath);
        $bootstrapper = new Bootstrapper($app);

        $this->expectException(PublishedConfigurationRequiredException::class);
        $this->expectExceptionMessage('Published configuration is required for bootstrap');

        $bootstrapper->bootstrapPlan($plan);
    }

    public function test_build_and_bootstrap_context_named_constructors_capture_minimum_contract(): void
    {
        $buildContext = BuildContext::forEnvironment(
            environment: 'production',
            profile: 'web',
            artifactDirectory: $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bootstrap',
            releaseId: 'release-001',
        );

        $bootstrapContext = BootstrapContext::forConsole(
            environment: 'production',
            artifactPolicy: 'require-artifacts',
            executionMode: 'single',
            profile: 'web',
        );

        self::assertSame('production', $buildContext->environment());
        self::assertSame('web', $buildContext->profile());
        self::assertSame('release-001', $buildContext->releaseId());
        self::assertSame(
            $this->basePath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'bootstrap',
            $buildContext->artifactDirectory(),
        );

        self::assertSame('production', $bootstrapContext->environment());
        self::assertSame('require-artifacts', $bootstrapContext->artifactPolicy());
        self::assertSame('single', $bootstrapContext->executionMode());
        self::assertSame('web', $bootstrapContext->profile());
    }

    public function test_runtime_bootstrap_context_named_constructor_maps_persistent_and_single_execution_modes(): void
    {
        $persistent = BootstrapContext::forRuntime(
            environment: 'production',
            persistent: true,
            profile: 'worker',
        );
        $single = BootstrapContext::forRuntime(
            environment: 'production',
            persistent: false,
            profile: 'web',
        );

        self::assertSame('worker', $persistent->executionMode());
        self::assertSame('worker', $persistent->profile());
        self::assertSame('single', $single->executionMode());
        self::assertSame('web', $single->profile());
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
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;

            if (is_file($target) || is_link($target)) {
                @unlink($target);
                continue;
            }

            $this->deleteDirectory($target);
        }

        @rmdir($path);
    }
}

final class TestPlanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('test.plan.provider.state', 'registered');
    }

    public function boot(): void
    {
        $this->app->instance('test.plan.provider.state', 'booted');
    }
}
