<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use Quantum\Bootstrap\ApplicationBuilder;
use Quantum\Bootstrap\Bootstrapper;
use Quantum\Bootstrap\Context\BootstrapContext;
use Quantum\Bootstrap\Discovery\ProviderMetadata;
use Quantum\Bootstrap\Exception\DependencyCycleException;
use Quantum\Bootstrap\Exception\MissingProviderDependencyException;
use Quantum\Bootstrap\Graph\ProviderDependencySorter;
use Quantum\Bootstrap\Phase\BootstrapState;
use Quantum\Bootstrap\Phase\BootstrapStateMachine;
use VoltStack\Framework\Application;
use VoltStack\Framework\ServiceProvider;

final class BootstrapCoreDeterminismTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        BootstrapCoreOrderRecorder::$events = [];
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-bootstrap-core-' . uniqid('', true);
        mkdir($this->basePath . DIRECTORY_SEPARATOR . 'config', 0777, true);

        file_put_contents(
            $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php',
            <<<PHP
<?php

return [
    'name' => 'VoltStack Bootstrap Core',
    'env' => 'testing',
];
PHP
        );
    }

    protected function tearDown(): void
    {
        $configFile = $this->basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        if (is_file($configFile)) {
            unlink($configFile);
        }

        $configDirectory = $this->basePath . DIRECTORY_SEPARATOR . 'config';
        if (is_dir($configDirectory)) {
            rmdir($configDirectory);
        }

        if (is_dir($this->basePath)) {
            rmdir($this->basePath);
        }

        parent::tearDown();
    }

    public function test_state_machine_allows_the_happy_path_and_rejects_backwards_transitions(): void
    {
        $machine = new BootstrapStateMachine();

        self::assertSame(BootstrapState::New, $machine->current());

        $machine->transitionTo(BootstrapState::Discovering);
        $machine->transitionTo(BootstrapState::Registering);
        $machine->transitionTo(BootstrapState::Configuring);
        $machine->transitionTo(BootstrapState::Compiling);
        $machine->transitionTo(BootstrapState::Booting);
        $machine->transitionTo(BootstrapState::Warming);
        $machine->transitionTo(BootstrapState::Ready);

        self::assertSame(BootstrapState::Ready, $machine->current());
        self::assertFalse($machine->canTransitionTo(BootstrapState::Registering));

        $this->expectException(LogicException::class);
        $machine->transitionTo(BootstrapState::Registering);
    }

    public function test_provider_dependency_sorter_orders_providers_deterministically(): void
    {
        $sorter = new ProviderDependencySorter();

        $ordered = $sorter->sort([
            BootstrapGraphLeafProvider::class,
            BootstrapGraphRootProvider::class,
            BootstrapGraphWebOnlyProvider::class,
            BootstrapGraphMiddleProvider::class,
        ], 'testing', 'console');

        self::assertSame([
            BootstrapGraphRootProvider::class,
            BootstrapGraphMiddleProvider::class,
            BootstrapGraphLeafProvider::class,
        ], $ordered);
    }

    public function test_provider_dependency_sorter_rejects_missing_requirements(): void
    {
        $sorter = new ProviderDependencySorter();

        $this->expectException(MissingProviderDependencyException::class);
        $sorter->sort([BootstrapMissingDependencyProvider::class], 'testing', 'console');
    }

    public function test_provider_dependency_sorter_rejects_cycles(): void
    {
        $sorter = new ProviderDependencySorter();

        $this->expectException(DependencyCycleException::class);
        $sorter->sort([
            BootstrapCycleAProvider::class,
            BootstrapCycleBProvider::class,
        ], 'testing', 'console');
    }

    public function test_bootstrapper_registers_providers_using_sorted_metadata_order(): void
    {
        $plan = ApplicationBuilder::create($this->basePath)
            ->withEnvironment('testing')
            ->withProfile('console')
            ->withProviders([
                BootstrapGraphLeafProvider::class,
                BootstrapGraphRootProvider::class,
                BootstrapGraphMiddleProvider::class,
                BootstrapGraphWebOnlyProvider::class,
            ])
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

        self::assertSame([
            'register:root',
            'register:middle',
            'register:leaf',
            'boot:root',
            'boot:middle',
            'boot:leaf',
        ], BootstrapCoreOrderRecorder::$events);
    }
}

final class BootstrapCoreOrderRecorder
{
    /**
     * @var list<string>
     */
    public static array $events = [];
}

final class BootstrapGraphRootProvider extends ServiceProvider
{
    public static function metadata(): ProviderMetadata
    {
        return new ProviderMetadata(id: 'bootstrap.root', priority: 50);
    }

    public function register(): void
    {
        BootstrapCoreOrderRecorder::$events[] = 'register:root';
    }

    public function boot(): void
    {
        BootstrapCoreOrderRecorder::$events[] = 'boot:root';
    }
}

final class BootstrapGraphMiddleProvider extends ServiceProvider
{
    public static function metadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            id: 'bootstrap.middle',
            requires: ['bootstrap.root'],
            priority: 10,
        );
    }

    public function register(): void
    {
        BootstrapCoreOrderRecorder::$events[] = 'register:middle';
    }

    public function boot(): void
    {
        BootstrapCoreOrderRecorder::$events[] = 'boot:middle';
    }
}

final class BootstrapGraphLeafProvider extends ServiceProvider
{
    public static function metadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            id: 'bootstrap.leaf',
            after: ['bootstrap.middle'],
        );
    }

    public function register(): void
    {
        BootstrapCoreOrderRecorder::$events[] = 'register:leaf';
    }

    public function boot(): void
    {
        BootstrapCoreOrderRecorder::$events[] = 'boot:leaf';
    }
}

final class BootstrapGraphWebOnlyProvider extends ServiceProvider
{
    public static function metadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            id: 'bootstrap.web-only',
            profiles: ['web'],
        );
    }

    public function register(): void
    {
        BootstrapCoreOrderRecorder::$events[] = 'register:web';
    }
}

final class BootstrapMissingDependencyProvider extends ServiceProvider
{
    public static function metadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            id: 'bootstrap.missing',
            requires: ['bootstrap.not-found'],
        );
    }

    public function register(): void
    {
    }
}

final class BootstrapCycleAProvider extends ServiceProvider
{
    public static function metadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            id: 'bootstrap.cycle-a',
            after: ['bootstrap.cycle-b'],
        );
    }

    public function register(): void
    {
    }
}

final class BootstrapCycleBProvider extends ServiceProvider
{
    public static function metadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            id: 'bootstrap.cycle-b',
            after: ['bootstrap.cycle-a'],
        );
    }

    public function register(): void
    {
    }
}
